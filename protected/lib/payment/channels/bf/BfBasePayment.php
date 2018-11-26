<?php

namespace app\lib\payment\channels\bf;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Remit;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use app\common\models\model\Order;
use Symfony\Component\DomCrawler\Crawler;
use Yii;

/**
 * 秒付
 * Class ShBasePayment
 * @package app\lib\payment\channels\sf
 */
class BfBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 'success';
    const  TRADE_STATUS_PROCESSING = 'paying';
    const  TRADE_STATUS_FAIL = 'failed';

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

        $requestJson = file_get_contents("php://input");
        if(empty($requestJson)){
            throw new OperationFailureException("回调json字符串为空");
        }

        $request = json_decode($requestJson,true);
        if(empty($request)){
            throw new OperationFailureException("回调json字符串转化为数组后为空");
        }

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
        $order = LogicOrder::getOrderByOrderNo($orderNo);
        $this->setPaymentConfig($order->channelAccount);
        $this->setOrder($order);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->order_no,
            'merchant_order_no'=>$order->merchant_order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY,
            'merchant_id'=>$order->merchant_id,
            'merchant_name'=>$order->merchant_account,
            'channel_account_id'=>$order->channelAccount->id,
            'channel_name'=>$order->channelAccount->channel_name,
        ];

        $localSign = self::md5Sign($request,trim($this->paymentConfig['key']));
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
     * 微信扫码支付
     */
    public function wechatQr()
    {

        $params = [
            'notify_url'=>str_replace('https','http',$this->getRechargeNotifyUrl()),
            'return_url'=>str_replace('https','http',$this->getRechargeReturnUrl()),
            'bank_code'=>'',
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>$this->order['pay_method_code'],
            'order_amount'=>$this->order['amount'],
            'order_time'=>time(),
            'customer_ip'=>Yii::$app->request->remoteIP,
        ];

        $params['sign'] = self::md5Sign($params,$this->paymentConfig['key']);

//        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/api/v1/order';
//        $resTxt = self::post($requestUrl,$params,[],25);
//        //接口日志记录
//        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);
//
//        $ret = self::RECHARGE_WEBBANK_RESULT;
//        if (!empty($resTxt)) {
//            $res = json_decode($resTxt, true);
//
//            if (isset($res['is_success']) && $res['is_success'] == 'TRUE') {
//                $ret['status'] = Macro::SUCCESS;
//                $ret['data']['channel_order_no'] = $res['trade_no'];
//
//                if(Util::isMobileDevice() && substr($res['url'],0,4)=='http'){
//                    $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
//                    $ret['data']['url'] = $res['url'];
//                }else{
//                    $ret['data']['type'] = self::RENDER_TYPE_QR;
//                    $ret['data']['qr'] = $res['url'];
//                }
//            } else {
//                $ret['message'] = $res['msg']??'付款提交失败';
//            }
//        }
//
//        return $ret;

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/api/v1/order';
        $getUrl = $requestUrl.'?'.http_build_query($params);

        //是否跳过宝付
        $skipHt = true;
        $formTxt = '';
        if($skipHt){
            //跳过上游第一个地址,达到隐藏上游目的.
            $htmlTxt = self::httpGet($getUrl);
            //接口日志记录
            LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $htmlTxt, $params);
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
            Yii::info(['bf jump: '.$jumpUrl,$jumpParams]);
            if($jumpUrl && $jumpParams){
                //第二跳
                $formTxt = self::buildForm( $jumpParams, $jumpUrl);
            }
        } else{
            Yii::info("do not skip payment redirect");
            $formTxt = self::buildForm($params, $requestUrl);
        }
        $formTxt = self::buildForm($params,$requestUrl);
        //接口日志记录
//        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $formTxt, $params);
        $ret = self::RECHARGE_CASHIER_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['formHtml'] = $formTxt;
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

        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code'],'remit');
        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'order_no'=>$this->remit['order_no'],
            'order_amount'=>$this->remit['amount'],
            'order_time'=>time(),
            'account_name'=>$this->remit['bank_account'],
            'account_number'=>$this->remit['bank_no'],
            'bank_code'=>$bankCode,//$this->remit['bank_code'],
        ];
        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'] . '/remit.html';
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit to bank result: '.$this->remit['order_no'].' '.$resTxt);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, Yii::$app->params['apiRequestLog']['http_code']??200,0, $params);

        $ret = self::REMIT_RESULT;
        $ret['data']['rawMessage'] = $resTxt;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['trade_no'];

                if($res['bank_status']=='pending' || $res['bank_status']=='processing'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }elseif($res['bank_status']=='success'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                }elseif($res['bank_status']=='failed'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = $res['msg']??"({$resTxt})";
                }
            } else {
                $ret['message'] = $res['msg']??"出款提交失败({$resTxt})";
            }
        }

        return  $ret;
    }

    /**
     * 提交出款状态查询
     *
     * @return array REMIT_QUERY_RESULT
     */
    public function remitStatus(){
        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $params = [
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'order_no'=>$this->remit['order_no'],
            'query_time'=>time(),
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
        $ret['data']['rawMessage'] = $resTxt;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['trade_no'];

                if($res['bank_status']=='pending' || $res['bank_status']=='processing'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }elseif($res['bank_status']=='success'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                }elseif($res['bank_status']=='failed'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = $res['msg']??"({$resTxt})";
                }
            } else {
                $ret['message'] = $res['msg']??"出款查询失败({$resTxt})";
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
            'order_no'=>$this->order['order_no'],
            'query_time'=>time(),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/query.html';
        $resTxt = self::post($requestUrl, $params);
        //记录请求日志
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::REMIT_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['order_id'];
                $ret['data']['trade_status'] = $res['trade_status'];
            } else {
                $ret['message'] = $res['msg']??'收款订单查询失败';
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
            'query_time'=>time(),
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
                $ret['data']['frozen_balance'] = $res['freeze_money']??0;
            } else {
                $ret['message'] = $res['msg']??'余额查询失败';
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
        unset($params['sign']);

        $signParams = [];
        foreach ($params as $key => $value) {
            if ($value == '') continue;
            $signParams[] = "$key=$value";
        }

        sort($signParams, SORT_STRING);
        $strToSign = implode('&', $signParams);

        $signStr = md5($strToSign . '&key=' . $signKey);
        Yii::info('md5Sign string: '.$signStr.' raw: '.$strToSign . '&key=' . $signKey);
        return $signStr;
    }

    /**
     *
     * 发送http get 请求
     *
     * @param sttring $url 请求地址
     * @param array $headers http header
     *
     * @return bool|string
     */
    public static function httpGet($url, $headers = [], $timeout = 20)
    {
        try {
            $client   = new \GuzzleHttp\Client();
            $response = $client->get($url,['timeout' => $timeout]);
            $httpCode = $response->getStatusCode();
            $body     = (string)$response->getBody();
        } catch (\Exception $e) {
            if ($e->hasResponse()) {
                $httpCode = $e->getResponse()->getStatusCode();
                $body = (string)$e->getResponse()->getBody();
            } else {
                $body = $e->getMessage();
                $httpCode = 403;
            }
        }

        Yii::info('request to channel: ' . $url . ' ' . $httpCode . ' ' . $body);

        return $body;
    }
}