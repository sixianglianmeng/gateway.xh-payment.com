<?php

    namespace app\modules\gateway\controllers\v1\web;

    use app\common\models\logic\LogicApiRequestLog;
    use app\common\models\model\BankCodes;
    use app\common\models\model\Channel;
    use app\common\models\model\LogApiRequest;
    use app\common\models\model\Order;
    use app\common\models\model\SiteConfig;
    use app\components\Macro;
    use app\components\Util;
    use app\components\WebAppController;
    use app\lib\helpers\ControllerParameterValidator;
    use app\lib\helpers\ResponseHelper;
    use app\lib\payment\ChannelPayment;
    use app\lib\payment\channels\BasePayment;
    use app\modules\gateway\models\logic\LogicOrder;
    use app\modules\gateway\models\logic\PaymentRequest;
    use Yii;

    /*
     * 充值跳转接口
     */

    class OrderPayController extends WebAppController
    {
        /**
         * 前置action
         *
         * @author booter.ui@gmail.com
         */
        public function beforeAction($action)
        {
            return parent::beforeAction($action);
        }

        /*
         * 订单付款
         */
        public function actionPay()
        {
            $orderNo = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNo', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误');
            $selectBankCode = ControllerParameterValidator::getRequestParam($this->allParams, 'bankCode', '', Macro::CONST_PARAM_TYPE_ALNUM, '银行代码错误');
            $token = ControllerParameterValidator::getRequestParam($this->allParams, 'token', '', Macro::CONST_PARAM_TYPE_STRING, 'token错误-404',[10]);

            $order = Order::findOne(['order_no' => $orderNo]);
            if (!$order) {
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单不存在:'.$orderNo);
            }

            //接口日志埋点
            Yii::$app->params['apiRequestLog'] = [
                'event_id'=>$orderNo,
                'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_CASHIER,
                'merchant_id'=>$order->merchant->id,
                'merchant_order_no'=>$order->merchant_order_no,
                'merchant_name'=>$order->merchant->username,
                'channel_account_id'=>$order->channelAccount->id,
                'channel_name'=>$order->channelAccount->channel_name,
            ];
            //设置了请求日志，写入日志表
            LogicApiRequestLog::inLog("");

            if (in_array($order->status, [Order::STATUS_PAID,Order::STATUS_SETTLEMENT])) {
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单已付款');
            }
//            $validity = SiteConfig::cacheGetContent('recharge_order_validity');
//            if($validity && ($order->created_at+intval($validity))<time()){
//                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单已过期,请重新下单');
//            }
            $ts = time();
            if($order->expire_time>0 && $order->expire_time<=$ts){
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, "订单已过期,请重新下单.");
            }

            $clientId = PaymentRequest::getClientId();
            if(!$clientId){
                //设置客户端唯一id
                PaymentRequest::setClientIdCookie();
                return $this->redirect(LogicOrder::getCashierUrl($orderNo)."&r=".time(), 302);
            }
            //更新客户端信息
            LogicOrder::updateClientInfo($order);

//            检测用户或者IP是否在黑名单中
            if(!PaymentRequest::checkBlackListUser()){
                $msg = '对不起，网络请求超时:'.Macro::ERR_USER_BAN;
                Util::sendTelegramMessage("用户充值因为处于黑名单被风控:订单号{$order->order_no},商户号{$order->merchant_id},IP:". Util::getClientIp().",设备号:{$clientId}}");
                return ResponseHelper::formatOutput(Macro::ERR_USER_BAN, $msg);
            }
            //检测referer
            if(!PaymentRequest::checkReferrer($order->userPaymentInfo)){
                $msg = '对不起，来路域名错误，请联系您的商户:'.Macro::ERR_REFERRER;
                return ResponseHelper::formatOutput(Macro::ERR_REFERRER, $msg);
            }
            //生成跳转连接
            $payment = new ChannelPayment($order, $order->channelAccount);

            $methodFnc = Channel::getPayMethodEnStr($order->pay_method_code);
            if (!is_callable([$payment, $methodFnc])) {
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, "对不起,系统中此通道暂未支持此支付方式.");
            }
            //检测网银对应银行代码是否正确,若不正确,显示选择页面
            if(in_array($order->pay_method_code,[Channel::METHOD_WEBBANK,Channel::METHOD_WEBBANK_NC])) {
                $bankCode = BankCodes::getChannelBankCode($order->channel_id, $order->bank_code);

                if ($selectBankCode && empty($bankCode)) {
                    if(!$this->checkOrderStatusQueryCsrfToken($order->order_no,$token)){
                        return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,'token校验失败');
                    }

                    $selectBank = BankCodes::getChannelBankCode($order->channel_id, $selectBankCode);
                    if ($selectBank){
                        $bankCode = $selectBankCode;
                        $order->bank_code = $bankCode;
                        $order->save();
                    }
                }

                if(empty($bankCode)){
                    $siteName = SiteConfig::cacheGetContent('site_name');
                    $this->view->title = $siteName.' - 选择银行';

                    $ret['token'] = $this->setOrderStatusQueryCsrfToken($order->order_no);
                    $ret['order'] = $order->toArray();
                    $ret['banks'] = BankCodes::getRechargeBankList($order->channel_id);

                    $response = $this->render('@app/modules/gateway/views/cashier/bank_select', [
                        'data' => $ret,
                    ]);

                    return $response;
                }
            }

            //由各方法自行处理响应
            //return redirect|QrCode view|h5 call native
            //检测缓存中是否已经有此订单的下单结果,如果有直接使用,防止报重复下单错误
            $cacheKey = "channel:cashier_ret:{$order->order_no}";
            $hasRequest =  Yii::$app->redis->get($cacheKey);
            if($hasRequest){
                Yii::info("get cached url, will not request sf:{$order->order_no}, {$hasRequest}");
                $ret = json_decode($hasRequest,true);
            }else{
                //锁定正在付款中,防止重复提交
                $payingCacheKey = "channel:cashier_paying:{$order->order_no}";
                $payingRet =  Yii::$app->redis->get($payingCacheKey);
                if($payingRet){
                    Util::throwException(Macro::ERR_UNKNOWN,"订单正在提交,请稍候再刷新页面进行支付!订单号:{$order->order_no},金额{$order->amount}");
                }
                Yii::$app->redis->set($payingCacheKey,time());

                //根据渠道对应处理方法获取下单结果
                $ret = $payment->$methodFnc();

                //获取到下单结果,删除锁
                Yii::$app->redis->del($payingCacheKey);
            }

            if ($ret['status']!==Macro::SUCCESS) {
                Yii::error("订单生成失败. 订单号:{$order->order_no}, 支付方式:{$order->pay_method_code}, 通道:{$order->channelAccount->channel_name}, 上游返回:{$ret['message']}");
                LogicOrder::payFail($order,"上游订单生成失败:{$ret['message']}");
                if($order->channel_id==10015 && SiteConfig::cacheGetContent('enable_notify_channel')){
                    if(strpos($ret['message'],'重复')===false && strpos($ret['message'],'存在')===false){
                        $telMsg = "充值订单生成失败,请排查\n订单号:{$order->order_no}\n支付方式:{$order->pay_method_code}\n商户号:{$order->channel_merchant_id}\n金额:{$order->amount}\n上游返回:{$ret['message']}";
                        Util::sendTelegramMessage($telMsg,'-278804726',false);
                    }
                }
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, "{$order->order_no}上游订单生成失败");
            }
            //下单结果写入缓存
            Yii::$app->redis->set($cacheKey,json_encode($ret,JSON_UNESCAPED_UNICODE));
            Yii::$app->redis->expire($cacheKey,43200);

            if (empty($ret['data']['type'])) {
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, "无法找到支付表单渲染方式");
            }

            //上游下单成功后,更新过期时间
            if(!$order->expire_time){
                $validity = SiteConfig::cacheGetContent('recharge_order_validity');
                if(!$validity) $validity = 300;
                $order->expire_time = $ts+$validity;
                $order->save();
            }

            //更新渠道订单号
            if (empty($order->channel_order_no) && !empty($ret['data']['channel_order_no'])) {
                $order->channel_order_no = $ret['data']['channel_order_no'];
                $order->save();
            }

            //更新为已打开收银台
            LogicOrder::updateCashierOpen($order);

            switch ($ret['data']['type']) {
                case BasePayment::RENDER_TYPE_REDIRECT:
                    LogicOrder::updateJumpUpstream($order);
                    if (!empty($ret['data']['formHtml'])) {
                        $response = $ret['data']['formHtml'];
                    } elseif (!empty($ret['data']['url'])) {
                        $response = $this->redirect($ret['data']['url'], 302);
                    }
                    break;
                case BasePayment::RENDER_TYPE_QR:
                    $siteName = SiteConfig::cacheGetContent('site_name');
                    $this->view->title = $siteName.' - 订单付款';

                    $ret['token'] = $this->setOrderStatusQueryCsrfToken($orderNo);
                    $ret['order']                   = $order->toArray();
                    $ret['order']['pay_method_str'] = Channel::getPayMethodsStr($order['pay_method_code']);
                    //更新二维码为中转地址,进行统计
                    $ret['data']['qr'] = LogicOrder::getJumpUpstreamUrl($order->order_no);

                    $view = '@app/modules/gateway/views/cashier/qr';
                    //支付宝h5扫码拦截,二维码显示的是跳转统计地址
                    if(in_array($order->pay_method_code,[Channel::METHOD_ALIPAY_QR,Channel::METHOD_ALIPAY_H5])
                        && Util::isMobileDevice()
                    ){
                        $view = '@app/modules/gateway/views/cashier/alipay_h5';
                    }

                    $response                       = $this->render($view, [
                        'data' => $ret,
                    ]);
                    break;
                case BasePayment::RENDER_TYPE_NATIVE:
                    $ret['order'] = $order;
                    $response     = $this->render('cashier/native', [
                        'data' => $ret,
                    ]);
                    break;
                default:
                    $response = ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, "无法找到支付表单渲染方式:" . $ret['data']['type']);
                    break;
            }

            return $response;
        }

        /*
         * 随机跳转
         *
         * 收银台提交之后随机跳几次,然后再往上游跳转.防止商户用服务器抓取页面,获取不到用户IP.
         * 在最后一跳获取用户IP,并真正提交到上游.
         */
        public function actionRandRedirect()
        {
            $sign = ControllerParameterValidator::getRequestParam($this->allParams, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, '签名错误', [10]);

            $data = Yii::$app->getSecurity()->decryptByPassword(base64_decode($sign), LogicOrder::RAND_REDIRECT_SECRET_KEY);
            $data = json_decode($data, true);
            if (empty($data['orderNo'])) {
                ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单号不存在');
            }

            //还需要跳转
            if ($data['leftRedirectTimes'] > 0) {
                $data['leftRedirectTimes']--;
                return $this->redirect(LogicOrder::generateRandRedirectUrl($data['orderNo'], $data['leftRedirectTimes']), 302);
            }

            return $this->redirect(LogicOrder::getCashierUrl($data['orderNo']), 302);
        }

        /*
         * 检测订单是否已经支付成功
         */
        public function actionCheckStatus()
        {
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

            $no = ControllerParameterValidator::getRequestParam($this->allParams, 'no', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误');
            $token = ControllerParameterValidator::getRequestParam($this->allParams, 'token', null, Macro::CONST_PARAM_TYPE_STRING, 'token错误-404',[10]);

            //最低频率为2秒
            $key = 'qr_recharge_status:'.md5(Util::getClientIp());
            $lastTs = Yii::$app->cache->get($key);
            if($lastTs && (time()-$lastTs)<2){
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,'频率受限');
            }
            Yii::$app->cache->set($key,time(),30);

            if(!$this->checkOrderStatusQueryCsrfToken($no,$token)){
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,'token校验失败');
            }

            $order = Order::findOne(['order_no'=>$no]);
            $ret = Macro::ERR_UNKNOWN;
            if($order && in_array($order->status,[Order::STATUS_PAID,Order::STATUS_SETTLEMENT])){
                $ret = Macro::SUCCESS;
            }

            //设置客户端唯一id
            PaymentRequest::setClientIdCookie();

            //更新客户端信息
            LogicOrder::updateClientInfo($order);

            return ResponseHelper::formatOutput($ret,$lastTs.' '.time());
        }

        /**
         * 代理跳转上游支付地址
         *
         * 用于统计二维码扫描等,需要先将三方下单结果存入缓存
         *
         */
        public function actionQrRedirect()
        {
            $orderNo = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNo', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误');

            $order = Order::findOne(['order_no' => $orderNo]);
            if (!$order) {
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单不存在:'.$orderNo);
            }

            if (in_array($order->status,[Order::STATUS_PAID,Order::STATUS_SETTLEMENT])) {
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单已付款:'.$orderNo);
            }

            $cacheKey = "channel:cashier_ret:{$order->order_no}";
            $ret =  Yii::$app->redis->get($cacheKey);
            if(!$ret){
                return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, '订单下单数据不存在');
            }
            $ret = json_decode($ret,true);

            LogicOrder::updateJumpUpstream($order);

            //接口日志埋点
            Yii::$app->params['apiRequestLog'] = [
                'event_id'=>$orderNo,
                'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_REDIRECT,
                'merchant_id'=>$order->merchant->id,
                'merchant_order_no'=>$order->merchant_order_no,
                'merchant_name'=>$order->merchant->username,
                'channel_account_id'=>$order->channelAccount->id,
                'channel_name'=>$order->channelAccount->channel_name,
            ];

            //设置了请求日志，写入日志表
            $url = !empty($ret['data']['url'])?$ret['data']['url']:$ret['data']['qr'];
            //ios支付充值,使用app协议跳转
            if(substr(strtoupper($url),0,21)=='HTTPS://QR.ALIPAY.COM' && Util::isMobileDevice() && Util::isIosDevice()){
                $url = 'alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode='.urlencode($url).'&_t='.time();
                Yii::info("ios alipay: {$url}");
            }
            LogicApiRequestLog::inLog("redirect:".$url);

            return $this->redirect($url, 302);
        }


        /**
         * 生成csrf token
         * @param $orderNo
         *
         * @return string
         * @throws \yii\base\Exception
         */
        protected function setOrderStatusQueryCsrfToken($orderNo)
        {
            $key = 'qr_recharge_status_crsf:'.$orderNo;
            $token = Yii::$app->security->maskToken(Yii::$app->getSecurity()->generateRandomString());
            Yii::$app->cache->set($key,$token,300);

            return $token;
        }

        /**
         * 检测csrf token
         * @param $orderNo
         * @param $token
         *
         * @return bool
         */
        protected function checkOrderStatusQueryCsrfToken($orderNo, $token)
        {
            $key = 'qr_recharge_status_crsf:'.$orderNo;

            return Yii::$app->cache->get($key) == $token;
        }
    }
