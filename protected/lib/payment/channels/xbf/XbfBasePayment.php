<?php

    namespace app\lib\payment\channels\xbf;

    use app\common\exceptions\OperationFailureException;
    use app\common\models\logic\LogicApiRequestLog;
    use app\common\models\model\Channel;
    use app\common\models\model\LogApiRequest;
    use app\common\models\model\Order;
    use app\components\Macro;
    use app\components\Util;
    use app\lib\helpers\ControllerParameterValidator;
    use app\lib\payment\channels\BasePayment;
    use app\modules\gateway\models\logic\LogicOrder;
    use power\yii2\net\exceptions\SignatureNotMatchException;
    use Yii;

    /**
     * 鑫宝付支付接口
     *
     * @package app\lib\payment\channels\xbf
     */
    class XbfBasePayment extends BasePayment
    {
        const  PAY_TYPE_MAPS = [
            Channel::METHOD_ALIPAY_QR       => 'alipay',
            Channel::METHOD_ALIPAY_H5       => 'alipay',
        ];

        public function __construct(...$arguments)
        {
            parent::__construct(...$arguments);
        }

        /*
         * 解析异步通知请求，返回订单
         *
         * @return array self::RECHARGE_NOTIFY_RESULT
         */
        public function parseNotifyRequest(array $request){
            //check sign
            return $this->parseReturnRequest($request);
        }

        /*
         * 解析同步通知请求，返回订单
         * 返回订单对象表示请求验证成功且已经支付成功，可进行下一步业务
         * 返回int表示请求验证成功，订单未支付完成,int为订单在三方的状态
         * 其它表示错误
         *
         * @return array self::RECHARGE_NOTIFY_RESULT
         */
        public function parseReturnRequest(array $request){

            //按照文档获取所有签名参数,某些渠道签名参数不固定,也可以直接获取所有request
            $callbackParamsName = ['app_id','order_sn','amount','payment_sn','nonce_str','notify_count','sign'];
            $data = [];
            foreach ($callbackParamsName as $p){
                if(isset($request[$p])){
                    $data[$p] = $request[$p];
                }
            }

            //验证必要参数
            $data['order_sn']      = ControllerParameterValidator::getRequestParam($data, 'order_sn', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
            $data['amount']  = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
            $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

            $order = LogicOrder::getOrderByOrderNo($data['order_sn']);
            $this->setPaymentConfig($order->channelAccount);
            $this->setOrder($order);

            //接口日志埋点
            Yii::$app->params['apiRequestLog'] = [
                'event_id'=>$order->order_no,
                'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY,
                'merchant_id'=>$order->merchant_id,
                'merchant_name'=>$order->merchant_account,
                'channel_account_id'=>$order->channelAccount->id,
                'channel_name'=>$order->channelAccount->channel_name,
            ];

            $localSign = self::md5Sign($data, $this->paymentConfig['key']);
            if($sign != $localSign){
                Yii::error("xbf sign error: {$order->order_no}");
                $status = $this->orderStatus();//LogicOrder::queryChannelOrderStatus($order);
                //通过主动查询订单状态,跳过签名问题
                if($status['status']==Macro::SUCCESS && $status['data']['trade_status']==Order::STATUS_PAID){

                }else{
                    throw new SignatureNotMatchException("签名验证失败");
                }
            }

            $ret = self::RECHARGE_NOTIFY_RESULT;
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['channel_order_no'] = $data['payment_sn'];

            return $ret;
        }

        /*
         * 支付宝扫码支付
         */
        public function alipayQr()
        {
            if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
                throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                    Macro::ERR_PAYMENT_BANK_CODE);
            }

            if($this->order['amount']<1 || $this->order['amount']!=intval($this->order['amount'])){
                //            throw new OperationFailureException("充值金额不能低于1,且必须为整数",Macro::ERR_PAYMENT_BANK_CODE);
            }

            $params = [
                'app_id'=>$this->paymentConfig['app_id'],
                'channel'=>self::PAY_TYPE_MAPS[$this->order['pay_method_code']],
                'order_sn'=>$this->order['order_no'],
                'amount'=>ceil($this->order['amount']),
                'notify_url'=>$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/xbf/notify",
                'return_url'=>$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/xbf/return",
                'nonce_str'=>md5(Util::uuid()),
            ];

            $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
            $requestUrl = $this->paymentConfig['gateway_base_uri']."/pay/json";
            $resTxt = self::post($requestUrl,$params);

            //接口日志记录
            LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);

            $ret = self::RECHARGE_CASHIER_RESULT;
            if (!empty($resTxt)) {
                $res = json_decode($resTxt, true);

                if (isset($res['code']) && $res['code'] == '200'
                    && !empty($res['data']['cashier_url'])
                ) {
                    $ret['status'] = Macro::SUCCESS;
                    $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                    $ret['data']['url'] = $res['data']['cashier_url'];
                    $ret['data']['channel_order_no'] = $res['data']['system_sn'];
                } else {
                    $ret['message'] = $res['msg']??'付款提交失败';
                }
            }

            return $ret;
        }

        /**
         * 支付宝H5支付
         */
        public function alipayH5()
        {
            return $this->alipayQr();
        }

        /**
         * 收款订单状态查询
         *
         * @return array
         */
        public function orderStatus(){
            $params = [
                'app_id'=>$this->paymentConfig['app_id'],
                'order_sn'=>$this->order['order_no'],
                'nonce_str'=>md5(Util::uuid()),
            ];
            $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

            $requestUrl = $this->paymentConfig['gateway_base_uri']."/query";
            $resTxt = self::post($requestUrl, $params);
            Yii::info('remit query result: '.$this->order['order_no'].' '.$resTxt);
            $ret = self::RECHARGE_QUERY_RESULT;
            if (!empty($resTxt)) {
                $res = json_decode($resTxt, true);

                if (
                    isset($res['code']) && $res['code'] == '200'
                    && isset($res['data']['status']) && $res['data']['status']==1
                    && !empty($res['data']['amount'])
                ) {
                    $localSign = self::md5Sign($res['data'],trim($this->paymentConfig['key']));
                    if($localSign == $res['data']['sign']){
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['amount'] = $res['data']['amount'];
                        $ret['data']['trade_status'] = Order::STATUS_PAID;
                        $ret['data']['channel_order_no'] = $res['data']['system_sn'];
                    }
                } else {
                    $ret['message'] = $res['message']??'订单查询失败';
                }
            }

            return  $ret;
        }

        /**
         * 余额查询,此通道没有余额查询接口.但是需要做伪方法,防止批量实时查询失败.
         *
         * return  array BasePayment::BALANCE_QUERY_RESULT
         */
        public function balance()
        {
        }

        /**
         * 生成通知响应内容
         *
         * @param boolean $isSuccess
         * @return string
         */
        public static function createdResponse($isSuccess)
        {
            $str = 'FAIL';
            if($isSuccess){
                $str = 'SUCCESS';
            }
            return $str;
        }

        /**
         *
         * 发送post请求
         *
         * @param string $url 请求地址
         * @param array $postData 请求数据
         *
         * @return bool|string
         */
        public static function post(string $url, array $postData, $header = [], $timeout = 10)
        {
            $headers = [];
            try {
                $ch = curl_init(); //初始化curl
                curl_setopt($ch,CURLOPT_URL, $url);//抓取指定网页
                curl_setopt($ch, CURLOPT_HEADER, 0);//设置header
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
                curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                $body = curl_exec($ch);//运行curl
                curl_close($ch);
            } catch (\Exception $e) {
                $body     = $e->getMessage();
            }

            Yii::info('request to channel: ' . $url . ' ' . json_encode($postData,JSON_UNESCAPED_UNICODE). ' ' . $body);

            return $body;
        }

        /**
         *
         * 获取参数排序md5签名
         *
         * @param array $params 要签名的参数数组
         * @param string $signKey 签名密钥
         *
         * @return bool|string
         */
        public static function md5Sign(array $params, string $signKey){
            unset($params['app_key']);
            unset($params['sign']);
            ksort($params);

            $params['app_key'] = $signKey;
            $str = urldecode(http_build_query($params));

            $signStr = md5($str);
            Yii::info('md5Sign string: '.$str.' '.$signStr);
            return $signStr;
        }
    }
