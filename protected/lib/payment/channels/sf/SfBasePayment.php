<?php

namespace app\lib\payment\channels\sf;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\Channel;
use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;
use app\common\models\model\Order;
use Yii;

/**
 * 速付
 *
 * Class SfBasePayment
 * @package app\lib\payment\channels\sf
 */
class SfBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 'success';
    const  TRADE_STATUS_PROCESSING = 'paying';
    const  TRADE_STATUS_FAIL = 'failed';

    const PAY_TYPE_MAP = [
        Channel::METHOD_WEBBANK=>1,
        Channel::METHOD_WECHAT_QR=>2,
        Channel::METHOD_ALIPAY_QR=>3,
        Channel::METHOD_QQ_QR=>5,
        Channel::METHOD_UNIONPAY_QR=>7,
        Channel::METHOD_WECHAT_H5=>2,
        Channel::METHOD_ALIPAY_H5=>3,
        Channel::METHOD_QQ_H5=>5,
        Channel::METHOD_BANK_QUICK=>1,
        Channel::METHOD_JD_H5=>14,
        Channel::METHOD_JD_QR=>17,
        Channel::METHOD_UNIONPAY_H5=>7,
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

        $data['order_no'] = ControllerParameterValidator::getRequestParam($request, 'order_no',null,Macro::CONST_PARAM_TYPE_STRING, '订单号错误！');
        $data['order_amount'] = ControllerParameterValidator::getRequestParam($request, 'order_amount',null,Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['order_time'] = ControllerParameterValidator::getRequestParam($request, 'order_time',null,Macro::CONST_PARAM_TYPE_STRING, '订单时间错误！',[3]);
        $data['return_params'] = ControllerParameterValidator::getRequestParam($request, 'notifyTime','',Macro::CONST_PARAM_TYPE_STRING, '订单返回参数错误！',[3]);
        $data['merchant_code'] = ControllerParameterValidator::getRequestParam($request, 'merchant_code',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);
        $data['trade_no'] = ControllerParameterValidator::getRequestParam($request, 'trade_no',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单号错误！',[3]);
        $data['trade_time'] = ControllerParameterValidator::getRequestParam($request, 'trade_time',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单时间错误！',[3]);
        $data['trade_status'] = ControllerParameterValidator::getRequestParam($request, 'trade_status',null,Macro::CONST_PARAM_TYPE_STRING, '状态错误！',[3]);
        $data['notify_type'] = ControllerParameterValidator::getRequestParam($request, 'notify_type',null,Macro::CONST_PARAM_TYPE_STRING, '通知类型错误！',[3]);
        $data['merchant_code'] = ControllerParameterValidator::getRequestParam($request, 'merchant_code',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);
        $data['return_params'] = ControllerParameterValidator::getRequestParam($request, 'return_params','',Macro::CONST_PARAM_TYPE_STRING, 'return_params错误！');

        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);
        //修复某段时间订单号携带_的bug
        $orderNo = $data['order_no'];
        if(strpos($data['order_no'],'_')!==false){
            $orderNoArr = explode('_',$data['order_no']);
            $orderNo = $orderNoArr[0];
        }

        $order = LogicOrder::getOrderByOrderNo($orderNo);
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

        $localSign = self::md5Sign($data,trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(!empty($request['trade_status']) && $request['trade_status'] == self::TRADE_STATUS_SUCCESS) {
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['order_amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['channel_order_no'] = $data['trade_no'];
        }
        elseif(!empty($request['trade_status']) && $request['trade_status'] == self::TRADE_STATUS_FAIL) {
            $ret['status'] =  Macro::FAIL;
        }
        else{
            $ret['status'] =  Macro::ERR_PAYMENT_PROCESSING;
        }

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($ret);

        return $ret;
    }

    /*
     * 生成网银支付跳转参数连接
     *
     * return array ['url'=>'get跳转链接','formHtml'=>'自动提交的form表单HTML']
     */
    public function webBank()
    {

        $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);
        if($this->order['pay_method_code']==Channel::METHOD_WEBBANK && empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        if(empty(self::PAY_TYPE_MAP[$this->order['pay_method_code']])){
            throw new OperationFailureException("通道配置不支持此支付方式:".$this->order['pay_method_code'],Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        $params = [
            'notify_url'=>str_replace('https','http',$this->getRechargeNotifyUrl()),//$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/notify",
            'return_url'=>str_replace('https','http',$this->getRechargeReturnUrl()),//$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/return",
            'bank_code'=>$bankCode,
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>self::PAY_TYPE_MAP[$this->order['pay_method_code']],
            'order_amount'=>$this->order['amount'],
            'req_referer'=>'127.0.0.1',//Yii::$app->request->referrer?Yii::$app->request->referrer:Yii::$app->request->getHostInfo().Yii::$app->request->url,
            'order_time'=>date("Y-m-d H:i:s"),
            'customer_ip'=>Yii::$app->request->remoteIP,
            'return_params'=>$this->order['order_no'],
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/pay.html';
        $getUrl = $requestUrl.'?'.http_build_query($params);

        //是否跳过汇通
        $skipHt = true;
        $form = '';
        if($skipHt){
            //跳过上游第一个地址,达到隐藏上游目的.
            $htmlTxt = self::httpGet($getUrl);

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
//            Yii::info([$jumpUrl,$jumpParams]);
            if($jumpUrl && $jumpParams){
                //第二跳

                $form = self::buildForm( $jumpParams, $jumpUrl);
            }
        }
        else{
            Yii::info("can not skip ht payment redirect");
            $form = self::buildForm($params, $requestUrl);
        }

        $ret = self::RECHARGE_WEBBANK_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['url'] = $getUrl;
        $ret['data']['formHtml'] = $form;

        return $ret;
    }

    /*
     * 微信扫码支付
     */
    public function wechatQr()
    {
        if(empty(self::PAY_TYPE_MAP[$this->order['pay_method_code']])){
            throw new OperationFailureException("通道配置不支持此支付方式:".$this->order['pay_method_code'],Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        $params = [
            'notify_url'=>str_replace('https','http',$this->getRechargeNotifyUrl()),//$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/notify",
            'return_url'=>str_replace('https','http',$this->getRechargeReturnUrl()),//$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/return",
            'bank_code'=>'',
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>self::PAY_TYPE_MAP[$this->order['pay_method_code']],
            'order_amount'=>$this->order['amount'],
            'req_referer'=>'127.0.0.1',//Yii::$app->request->referrer?Yii::$app->request->referrer:Yii::$app->request->getHostInfo().Yii::$app->request->url,
            'order_time'=>date("Y-m-d H:i:s"),
            'customer_ip'=>Yii::$app->request->remoteIP,
            'return_params'=>$this->order['order_no'],
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/order.html';
        $resTxt = self::post($requestUrl,$params);

        $ret = self::RECHARGE_WEBBANK_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (isset($res['flag']) && $res['flag'] == '00') {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['transId'];

                if(!empty($res['qrCodeUrl'])){
                    if(Util::isMobileDevice() && substr($res['qrCodeUrl'],0,4)=='http'){
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

        return $ret;
    }


    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        return $this->wechatQr();
    }

    /**
     * QQ扫码支付
     */
    public function qqQr()
    {
        return $this->wechatQr();
    }


    /**
     * 网银快捷支付
     */
    public function bankQuickPay()
    {
        return $this->wechatQr();
    }

    /**
     * 微信快捷扫码支付
     */
    public function wechatQuickQr()
    {
        return $this->wechatQr();
    }

    /**
     * 微信H5支付
     */
    public function wechatH5()
    {
        return $this->wechatQr();

    }

    /**
     * 支付宝H5支付
     */
    public function alipayH5()
    {
        return $this->wechatQr();
    }


    /**
     * QQ H5支付
     */
    public function qqH5()
    {
        return $this->wechatQr();
    }

    /**
     * 京东钱包支付
     */
    public function jdWallet()
    {
        return $this->wechatQr();
    }

    /**
     * 银联微信扫码支付
     */
    public function unoinPayQr()
    {
        return $this->wechatQr();
    }


    /**
     * 京东H5支付
     */
    public function jdh5()
    {
        return $this->wechatQr();
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

        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code']);
        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'trade_no'=>$this->remit['order_no'],
            'order_amount'=>$this->remit['amount'],
            'order_time'=>date("Y-m-d H:i:s"),
            'account_name'=>$this->remit['bank_account'],
            'account_number'=>$this->remit['bank_no'],
            'bank_code'=>$bankCode,//$this->remit['bank_code'],
        ];
        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'] . '/remit.html';
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit to bank result: '.$this->remit['order_no'].' '.$resTxt);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::REMIT_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success'])) $ret['status'] = Macro::SUCCESS;
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['data']['channel_order_no'] = $res['order_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = $res['bank_status'];
            } else {
                $ret['message'] = $res['errror_msg']??"出款提交失败({$resTxt})";
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
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'trade_no'=>$this->remit['order_no'],
            'now_date'=>date("Y-m-d H:i:s"),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/remit_query.html';
        $resTxt = self::post($requestUrl, $params);
        //记录请求日志
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success'])) $ret['status'] = Macro::SUCCESS;

            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['data']['channel_order_no'] = $res['order_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = $res['bank_status'];
                if($res['bank_status']==3){
                    $ret['message'] = $res['errror_msg']??"银行处理失败({$resTxt})";
                }
            } else {
                $ret['message'] = $res['errror_msg']??"出款查询失败({$resTxt})";;
            }
        }

        return  $ret;
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'merchant_code'=>$this->order['channel_merchant_id'],
            'trade_no'=>$this->order['order_no'],
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/query.html';
        $resTxt = self::post($requestUrl, $params);

        $ret = self::REMIT_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['order_id'];
                $ret['data']['trade_status'] = $res['trade_status'];
            } else {
                $ret['message'] = $res['errror_msg']??'收款订单查询失败';
            }
        }

        return  $ret;
    }

    /*
     * 查询余额
     *
     * @return array
     */
    public function balance(){
        $params = [
            'merchant_code'=>$this->paymentConfig['merchantId'],
            'query_time'=>date("Y-m-d H:i:s"),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/balance.html';
        $resTxt = self::post($requestUrl, $params);

        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['is_success']) && strtoupper($res['is_success'])== 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['balance'] = $res['money'];
                $ret['data']['frozen_balance'] = $res['freeze_money'];
            } else {
                $ret['message'] = $res['errror_msg']??'余额查询失败';
            }
        }

        return  $ret;
    }

    /**
     * 生成通知响应内容
     *
     * @param boolean $isSuccess
     * @return string
     */
    public static function createdResponse($isSuccess)
    {
        $str = 'fail';
        if($isSuccess){
            $str = 'success';
        }
        return $str;
    }

    /**
     * 获取支付下一跳的地址/post表单
     *
     * @param string $url
     * @param $postParams
     * return array ['url'=>'','params'=>[]]
     */
    public function getNextPaymentForm($url, $postParams=null)
    {
        if($postParams){
            $htmlTxt = self::post($url,$postParams);
        }
        $htmlTxt = self::httpGet($url);

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

        return ['url'=>$jumpUrl,'params'=>$jumpParams];
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
    public static function md5Sign(array $params, string $signKey)
    {
        unset($params['key']);

        $signParams = [];
        foreach ($params as $key => $value) {
//            if ($value == '') continue;
            $signParams[] = "$key=$value";
        }

        sort($signParams, SORT_STRING);
        $strToSign = implode('&', $signParams);


        $signStr = md5($strToSign . '&key=' . $signKey);
        Yii::info('rsaSign string: '.$signStr.' raw: '.$strToSign);
        return $signStr;
    }
}