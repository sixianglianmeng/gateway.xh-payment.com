<?php

namespace app\lib\payment\channels\ffb;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\Channel;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Remit;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;
use Yii;
use app\common\models\model\Order;

/**
 * 密付支付接口
 *
 * @package app\lib\payment\channels\mf
 */
class FfbBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_ALIPAY_QR   => 'ALIPAY',
        Channel::METHOD_ALIPAY_H5   => 'H5_ALIPAY',
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
        //按照文档获取所有签名参数,某些渠道签名参数不固定,也可以直接获取所有request
        $callbackParamsName = ['memberid','orderid','amount','transaction_id','datetime','returncode'];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['orderid']      = ControllerParameterValidator::getRequestParam($data, 'orderid', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount']  = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['orderid']);
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
        $localSign = strtoupper(self::md5Sign($data, $this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }
        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;
        $ret['message'] = '';
        if (!empty($data['returncode']) && $data['returncode'] == '00' && $data['amount']> 0 ) {
            $ret['data']['amount'] = $data['amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['channel_order_no'] = $data['transaction_id'];
        }
        return $ret;
        //check sign
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
        return $this->parseNotifyRequest($request);
    }



    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }
        $params = [


            'pay_memberid'=>$this->order['channel_merchant_id'],
            'pay_orderid'=>$this->order['order_no'],
            'pay_bankcode'=>'903',
            'pay_amount'=>$this->order['amount'],
            'pay_notifyurl' => str_replace('http','http',$this->getRechargeNotifyUrl()),
            'pay_callbackurl' => str_replace('http','http',$this->getRechargeReturnUrl()),
            'pay_applydate'=>date('Y-m-d H:i:s'),
        ];
        //备注：notify_url, return_url, device, variables 不参与签名！！！
        $signParams = $params;
        $params['pay_md5sign'] = strtoupper(self::md5Sign($signParams,trim($this->paymentConfig['key'])));
        $params['pay_productname'] = '充值';
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/Pay_Index.html";
//        $resTxt = self::post($requestUrl,$params);
        $htmlTxt = self::post($requestUrl,$params);
        $ret = self::RECHARGE_WEBBANK_RESULT;
        if ($htmlTxt){
            $crawler = new Crawler($htmlTxt);
            $jumpUrl = '';
            foreach ($crawler->filter('form') as $n){
                $jumpUrl = $n->getAttribute('action');
            }
            $jumpParams = [];
            foreach ($crawler->filter('form > input') as $input) {
                $field = $input->getAttribute('name');
                if(!$field) continue;
                $jumpParams[$field] = $input->getAttribute('value');
            }
            Yii::info([$this->order['order_no'],' FFB jump: '.$jumpUrl,$jumpParams]);
            if($jumpUrl && $jumpParams){
                //第二跳
                $lastHtml = self::post( $jumpUrl,$jumpParams);
                $res['qrCodeUrl'] = self::parseQr($lastHtml);
                Yii::info($this->order['order_no'].' qrCodeUrl: '.$res['qrCodeUrl'] .' FFB last jump:'.$lastHtml);
                if ($res['qrCodeUrl']) {
                    $ret['status'] = Macro::SUCCESS;
//                    $ret['data']['channel_order_no'] = $res['transId'];

                    if(!empty($res['qrCodeUrl'])){
                        Yii::info($this->order['order_no'].' ismobile:'.Util::isMobileDevice().'   qrcodeurl:'.$res['qrCodeUrl']);
                        if(Util::isMobileDevice() && strtolower(substr($res['qrCodeUrl'],0,4)) == 'http'){
                            $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                            $ret['data']['url'] = $res['qrCodeUrl'];
                        }else{
                            $ret['data']['type'] = self::RENDER_TYPE_QR;
                            $ret['data']['qr'] = $res['qrCodeUrl'];
                        }
                    }
                } else {
                    $ret['message'] = $res['msg']??'付款提交失败';
                }
            }
        }
        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, json_encode($ret,JSON_UNESCAPED_UNICODE), $params);


//        $form = self::buildForm($params, $requestUrl);
//        $ret = self::RECHARGE_WEBBANK_RESULT;
//        $ret['status'] = Macro::SUCCESS;
//        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
//        $ret['data']['url'] = $requestUrl;
////        $ret['data']['formHtml'] = $form;
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
            'pay_memberid'=>$this->order['channel_merchant_id'],
            'pay_orderid'=>$this->order['order_no'],
        ];
        $params['pay_md5sign'] = strtoupper(self::md5Sign($params,trim($this->paymentConfig['key'])));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/Pay_Trade_query.html";
        $resTxt = self::post($requestUrl, $params);
        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        //记录请求日志
        LogicApiRequestLog::outLog($requestUrl, 'POST', json_encode(json_decode($resTxt,true),JSON_UNESCAPED_UNICODE), 200,0, $params);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            $ret['data']['channel_order_no'] = $res['transaction_id'];
            if (isset($res['returncode']) && $res['returncode'] == '00'  && !empty($res['amount'])) {
                $ret['status']         = Macro::SUCCESS;
                $sign = $res['sign'];
                unset($res['sign']);
                $localSign = strtoupper(self::md5Sign($res,trim($this->paymentConfig['key'])));
                $ret['data']['trade_status'] = Order::STATUS_NOTPAY;
                if($localSign == $sign &&  strtolower($res['trade_state']) == 'success'){
                    $ret['data']['amount'] = $res['amount'];
                    $ret['data']['trade_status'] = Order::STATUS_PAID;
                }
            } else {
                $ret['message'] = $res['message']??'订单查询失败';
            }
        }
        return  $ret;
    }

    /**
     * 提交出款请求
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remit(){
        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }

        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code'],'remit');
        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }
        if(empty($this->remit['bank_province'])){
            $this->remit['bank_province'] = '北京市';
        }
        if(empty($this->remit['bank_city'])){
            $this->remit['bank_city'] = '北京市';
        }
        if(empty($this->remit['bank_branch'])){
            $this->remit['bank_branch'] = $this->remit['bank_name'].'北京市中关村分行';
        }
        $params = [
            'mchid'=>$this->remit['channel_merchant_id'],
            'out_trade_no'=>$this->remit['order_no'],
            'money'=>$this->remit['amount'],
            'bankname' => BankCodes::getBankNameByCode($this->remit['bank_code']),
            'subbranch' => $this->remit['bank_branch'],
            'accountname'=>$this->remit['bank_account'],
            'cardnumber'=>$this->remit['bank_no'],
//            'order_time'=>date("Y-m-d H:i:s"),
            'province'=>$this->remit['bank_province'],
            'city'=>$this->remit['bank_city'],
            'bank_code'=>$bankCode,//$this->remit['bank_code'],
            'extends'=>base64_encode(json_encode(['bank_code'=>$bankCode])),
        ];
        $params['pay_md5sign'] = strtoupper(self::md5Sign($params, trim($this->paymentConfig['key'])));

        $requestUrl = $this->paymentConfig['gateway_base_uri'] . '/Payment_Dfpay_add.html';
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit to bank result: '.$this->remit['order_no'].' '.$resTxt);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, Yii::$app->params['apiRequestLog']['http_code']??200,0, $params);

        $ret = self::REMIT_RESULT;
        $ret['data']['rawMessage'] = $resTxt;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['status']) && strtoupper($res['status']) == 'SUCCESS') {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['transaction_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
            } else {
                $ret['message'] = "出款提交失败({".json_encode(json_decode($resTxt,true),JSON_UNESCAPED_UNICODE)."})";
//                $ret['message'] = $res['msg']??"出款提交失败({$resTxt})";
            }
        }

        return  $ret;
    }

    /**
     * 提交出款状态查询
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remitStatus(){
        if(empty($this->remit)){
            throw new \app\common\exceptions\OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $params = [
            'mchid'=>$this->remit['channel_merchant_id'],
            'out_trade_no'=>$this->remit['order_no'],
//            'now_date'=>date("Y-m-d H:i:s"),
        ];
        $params['pay_md5sign'] = strtoupper(self::md5Sign($params,trim($this->paymentConfig['key'])));
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/Payment_Dfpay_query.html';
        $resTxt = self::post($requestUrl, $params);
        //记录请求日志
        LogicApiRequestLog::outLog($requestUrl, 'POST', json_encode(json_decode($resTxt,true),JSON_UNESCAPED_UNICODE), 200,0, $params);

        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;
        $ret['data']['rawMessage'] = $resTxt;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['status']) && strtolower($res['status']) == 'success') {
                //仅代表请求成功,不代表业务成功
                $ret['status'] = Macro::SUCCESS;
                if(!empty($res['transaction_id'])) $ret['data']['channel_order_no'] = $res['transaction_id'];
                // 1 成功  2 失败 3 处理中 4 待处理 5 审核驳回  6 待审核 7 交易不存在  8 未知状态
                if($res['refCode'] == 1){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                    $ret['message'] = $res['message']??json_encode(json_decode($resTxt,true),JSON_UNESCAPED_UNICODE);
                }elseif (in_array($res['refCode'],[2,5])){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = $res['refMsg']??"银行处理失败({".json_encode(json_decode($resTxt,true),JSON_UNESCAPED_UNICODE)."})";
                }elseif (in_array($res['refMsg'],[3,4,6])){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }
            } else {
                $ret['message'] = $res['errror_msg']??"出款查询失败({".json_encode(json_decode($resTxt,true),JSON_UNESCAPED_UNICODE)."})";
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
     * 获取参数排序md5签名
     *
     * @param array $params 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */
    public static function md5Sign(array $params, string $signKey){
        if (is_array($params)) {
            $a      = $params;
            $params = array();
            foreach ($a as $key => $value) {
                $params[] = "$key=$value";
            }
            sort($params,SORT_STRING);
            $params = implode('&', $params);
        } elseif (is_string($params)) {

        } else {
            return false;
        }

        $signStr = md5($params.'&key='.$signKey);
        Yii::info('md5Sign string: '.$signStr.' raw: '.$params.'&key='.$signKey);
        return $signStr;
    }
    /**
     * 获取收款订单异步通知地址
     * 需在配置文件中配置地址重写'/api/v1/callback/recharge-notify/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-notify',
     *
     * return string
     */
    public function getRechargeNotifyUrl()
    {
        return $this->paymentConfig['paymentNotifyBaseUri']."/api/v1/callback/recharge-notify/{$this->order->channel_id}";
    }

    /**
     * 获取收款订单同步步通知地址
     * 需在配置文件中配置地址重写'/api/v1/callback/recharge-return/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-return',
     *
     * return string
     */
    public function getRechargeReturnUrl()
    {
        return $this->paymentConfig['paymentNotifyBaseUri']."/api/v1/callback/recharge-return/{$this->order->channel_id}";
    }

    /**
     *
     */
    public function parseQr($html){
        preg_match('/var strcode = \'(.+)\';/',$html, $matchs);
//        Yii::info('QR:'.json_encode($matchs));
        return $matchs[1];

    }

}