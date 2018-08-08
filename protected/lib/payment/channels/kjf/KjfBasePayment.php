<?php

namespace app\lib\payment\channels\kjf;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\Channel;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Yii;

/**
 * 快捷付支付接口
 *
 * @package app\lib\payment\channels\kjf
 */
class KjfBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [

        Channel::METHOD_WECHAT_QR => '900',
        Channel::METHOD_WECHAT_H5 => '901',
        Channel::METHOD_ALIPAY_QR => '902',
        Channel::METHOD_ALIPAY_H5 => '903',
//        Channel::METHOD_WEBBANK => '904',//网银跳转收银台
        Channel::METHOD_WEBBANK => '905',//网银直连

        Channel::METHOD_QQ_QR => '907',
        Channel::METHOD_JD_QR => '908',
        Channel::METHOD_QQ_H5 => '909',
        Channel::METHOD_UNIONPAY_QR => '911',
        Channel::METHOD_UNIONPAY_QUICK => '912',
        Channel::METHOD_JD_H5 => '913',
        Channel::METHOD_WECHAT_CODEBAR => '914',
    ];

    CONST REMIT_FAIL_CODES = ['E412','E415','E418','E209'];
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
        $callbackParamsName = ["MerchantId","OrderId","TransactionId","FaceValue","PayMoney","PayMethod","TransactionTime","ErrCode",
//            "ErrMsg","Attach"
        ];

        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['OrderId'] = ControllerParameterValidator::getRequestParam($request, 'OrderId', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['FaceValue'] = ControllerParameterValidator::getRequestParam($request, 'FaceValue', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['ErrCode'] = ControllerParameterValidator::getRequestParam($request, 'ErrCode', null, Macro::CONST_PARAM_TYPE_INT, '状态错误！');
        $sign = ControllerParameterValidator::getRequestParam($request, 'Sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['OrderId']);
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

        $localSign =  self::md5Sign($request, trim($this->paymentConfig['key']));
        if($localSign != $sign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;

        if ($data['ErrCode']=='0000') {
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['amount'] = $data['FaceValue'];
            $ret['data']['channel_order_no'] = $data['TransactionId'];
            $ret['status'] = Macro::SUCCESS;
        }

        return $ret;
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
        //同步仅返回订单号，直接忽略
        $ret = self::RECHARGE_NOTIFY_RESULT;
        return $ret;
    }

    /**
     * 微信扫码支付
     */
    public function wechatQr()
    {
        $payTypeCode = $bankCode = '';
        //网银支付获取银行代码
        if($this->order['pay_method_code']==Channel::METHOD_WEBBANK){
            $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

            if(empty($bankCode)){
                throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
            }
        }

        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        //其他支付获取支付通道代码
        else{
            $payTypeCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'service' => 'directPay',
            'merchantId' => $this->order['channel_merchant_id'],
            'notifyUrl' => str_replace('https','http',$this->getRechargeNotifyUrl()),
//            'returnUrl' => str_replace('http','http',$this->getRechargeReturnUrl()),
            'signType' => 'MD5',
            'inputCharset' => 'UTF-8',
            'outOrderId' => $this->order['order_no'],
            'subject' => '账户充值',
            'body' => '账户充值',
            'transAmt' => bcadd(0, $this->order['amount'], 2),

            'payMethod' => $payTypeCode,
//            'defaultBank' => $bankCode,
//            'authCode' => '',
            'channel' => 'B2C',
            'cardAttr' => '01',
        ];
        if($bankCode){
            $params['defaultBank'] = $bankCode;
        }

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/serviceDirect.html";

        $ret = self::RECHARGE_CASHIER_RESULT;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['formHtml'] = self::buildForm($params,$requestUrl);
        $ret['status'] = Macro::SUCCESS;

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, '', $params);

        return $ret;

    }

    public function wechatH5()
    {
        return $this->wechatQr();
    }

    /**
     * 网银支付
     */
    public function webBank()
    {
        return $this->wechatQr();
    }

    /**
     * 网银快捷
     */
    public function bankQuickPay()
    {
        return $this->wechatQr();
    }

    /**
     * 京东扫码支付
     */
    public function jdQr()
    {
        return $this->wechatQr();

    }

    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        return $this->wechatQr();
    }

    public function alipayH5()
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

    public function qqH5()
    {
        return $this->wechatQr();
    }

    /**
     * 银联扫码支付
     */
    public function unoinPayQr()
    {
        return $this->wechatQr();
    }

    /**
     * 银联快捷支付
     */
    public function unoinPayQuick()
    {
        return $this->wechatQr();
    }


    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'merchantId' => $this->order['channel_merchant_id'],
            'orderId' => $this->order['order_no'],
            'payMoney' => bcadd(0, $this->order['amount'], 2),
            'payMethod' => self::PAY_TYPE_MAPS[$this->order['pay_method_code']]??'',
            'signType' => 'MD5',
        ];

        $params['sign'] =self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/queryDirect.html";
        $resTxt = self::post($requestUrl,$params);

        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt,true);

            if(is_array($res) && !empty($res['data']['sign'])) {
//                $localSign = self::md5Sign($res['data'], trim($this->paymentConfig['key']));
//                Yii::info('order query ret sign: ' . $this->order['order_no'] . ' local:' . $localSign . ' back:' . $res['data']['sign']);
                if (
                    isset($res['errcode']) && $res['errcode'] == '0000'
                ) {
                    if (
                        $res['data']['status'] == '1'
                        && $res['data']['flag'] == '1'
                        && !empty($res['data']['faceValue']))
                    {
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['amount'] = $res['data']['faceValue'];
                        $ret['data']['trade_status'] = Order::STATUS_PAID;
                    }
                } else {
                    $ret['message'] = '订单查询失败:' . $resTxt;
                }
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
        $params = [
            'version'=>'1.0',
            'merchantId'=>$this->paymentConfig['merchantId'],
            'signType' => 'MD5',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['remit_key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/queryBlance.html";
        $resTxt = self::post($requestUrl,$params);

        Yii::info('balance query result: '.$this->order['channel_merchant_id'].' '.$resTxt);
        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt,true);

            if(is_array($res) && !empty($res['sign'])) {
                $localSign = self::md5Sign($res, trim($this->paymentConfig['remit_key']));
                Yii::info('order query ret sign: ' . $this->order['order_no'] . ' local:' . $localSign . ' back:' . $res['sign']);
                if (
                    isset($res['errCode']) && $res['errCode'] == '0000'
                ) {
                    $ret['status']         = Macro::SUCCESS;
                    $ret['data']['balance'] = $res['totalBlance'];
                } else {
                    $ret['message'] = '余额查询失败:' . $resTxt;
                }
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
        $ret = self::REMIT_RESULT;

        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code'],'remit');

        if(empty($bankCode)){
            throw new OperationFailureException("通道讯通宝银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
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
            'version' => '1.0',
            'merchantId' => $this->remit['channel_merchant_id'],
            'batchNo' => $this->remit['order_no'],
            'batchAmt' => bcadd(0, $this->remit['amount'], 2),
            'defaultBank' => $bankCode,

            'accountNum' => $this->remit['bank_no'],
            'accountName' => $this->remit['bank_account'],
            'province' => $this->remit['bank_province'],//mb_convert_encoding($this->remit['bank_account'],'GBK'),

            'city' => $this->remit['bank_city'],
            'subbranch' => $this->remit['bank_branch'],
            'notifyUrl' => str_replace('http','http',$this->getRemitNotifyUrl()),
            'signType' => 'MD5',

        ];
        $this->paymentConfig['remit_key'] = 'wie1ay15qrhhwnnjr7yr7ywiz82c56fr';
        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['remit_key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'] . '/batchDirect.html';
        $resTxt     = self::post($requestUrl, $params);

        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200, 0, $params);

        Yii::info('remit to bank raw result: ' . $this->remit['order_no'] . ' ' . $resTxt);
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (is_array($res) && $res['errcode']=='0000') {
                $ret['data']['bank_status']      = Remit::BANK_STATUS_PROCESSING;
                $ret['status'] = Macro::SUCCESS;
            } elseif(is_array($res) && in_array($res['errcode'],self::REMIT_FAIL_CODES)) {
                $ret['data']['bank_status']      = Remit::BANK_STATUS_FAIL;
                $ret['status'] = Macro::SUCCESS;
                $ret['message'] = $res['errmsg'];
            } else {
                $ret['message'] = $res['errmsg']??$resTxt;
            }

        } else {
            $ret['message'] = "{$resTxt}";
        }

        return $ret;
    }

    /*
 * 解析出款异步通知请求，返回订单
 *
 * @return array self::RECHARGE_NOTIFY_RESULT
 */
    public function parseRemitNotifyRequest(array $request){
        //按照文档获取所有签名参数,某些渠道签名参数不固定,也可以直接获取所有request
        $callbackParamsName = ["errCode","errMsg","transactionId","batchNo","batchAmt","accountNum","accountName","attach",
            "sign"
        ];

        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        $data['batchNo'] = ControllerParameterValidator::getRequestParam($request, 'batchNo',null,Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['batchAmt'] = ControllerParameterValidator::getRequestParam($request, 'batchAmt',null,Macro::CONST_PARAM_TYPE_DECIMAL,'金额错误');
        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);

        $remit = LogicRemit::getOrderByOrderNo($data['batchNo']);
        $this->setPaymentConfig($remit->channelAccount);
        $this->setRemit($remit);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_REMIT_NOTIFY,
            'merchant_id'=>$remit->merchant_id,
            'merchant_name'=>$remit->merchant_account,
            'channel_account_id'=>$remit->channelAccount->id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];

        $localSign = self::md5Sign($data,trim($this->paymentConfig['remit_key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_QUERY_RESULT;
        $ret['data']['remit'] = $remit;
        $ret['data']['order_no'] = $remit->order_no;

        if(!empty($request['errCode']) && $request['errCode'] =='0000' && $data['batchAmt']>0) {
            $ret['data']['amount'] = $data['batchAmt'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
            $ret['data']['channel_order_no'] = $request['transactionId'];
        }elseif(in_array($request['errCode'],self::REMIT_FAIL_CODES)) {
            $ret['data']['bank_status']      = Remit::BANK_STATUS_FAIL;
            $ret['status'] = Macro::SUCCESS;
            $ret['message'] = $request['errMsg'];
        }

        return $ret;
    }

    /**
     * 提交出款状态查询
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remitStatus(){
        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;

        $params = [
            'version' => '1.0',
            'merchantId' => $this->remit['channel_merchant_id'],
            'batchNo' => $this->remit['order_no'],
            'signType' => 'MD5',
        ];

        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['remit_key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'] . '/batchQuery.html';
        $resTxt     = self::post($requestUrl, $params);

        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200, 0, $params);
        Yii::info('remit query raw result: ' . $this->remit['order_no'] . ' ' . $resTxt);
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (is_array($res) && $res['errCode']=='0000') {
                //返回的金额为出款金额+手续费金额
                if($res['batchAmt']>$this->remit['amount']) $res['batchAmt']=$this->remit['amount'];
                $ret['data']['bank_status']      = Remit::BANK_STATUS_SUCCESS;
                $ret['data']['amount'] = $res['batchAmt'];
                $ret['data']['channel_order_no'] = $res['transactionId'];
                $ret['status'] = Macro::SUCCESS;
            }elseif(is_array($res) && in_array($res['errCode'],self::REMIT_FAIL_CODES)) {
                $ret['data']['bank_status']      = Remit::BANK_STATUS_FAIL;
                $ret['status'] = Macro::SUCCESS;
                $ret['message'] = $res['errMsg'];
            }else {
                $ret['status'] =  Remit::BANK_STATUS_PROCESSING;
                $ret['message'] = $res['errMsg'];
            }

        } else {
            $ret['message'] = "{$resTxt}";
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
     * 发送post请求
     *
     * @param string $url 请求地址
     * @param array|string $postData 请求数据
     *
     * @return bool|string
     */
    public static function post(string $url, $postData, $header = [], $timeout = 20)
    {
        try {
            $ch = curl_init(); //初始化curl

            curl_setopt($ch, CURLOPT_URL, $url); // 要访问的地址
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 从证书中检查SSL加密算法是否存在
            curl_setopt($ch, CURLOPT_AUTOREFERER, 1); // 自动设置Referer
            curl_setopt($ch, CURLOPT_POST, 1); // 发送一个常规的Post请求
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // Post提交的数据包
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // 设置超时限制防止死循环
            curl_setopt($ch, CURLOPT_HEADER, 0); // 显示返回的Header区域内容
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // 获取的信息以文件流的形式返回;

            $body = curl_exec($ch);//运行curl
            curl_close($ch);
        } catch (\Exception $e) {
            $body     = $e->getMessage();
        }

        Yii::info('request to channel: ' . $url . ' data:' . http_build_query($postData). ' response:' . $body);

        return $body;
    }

    /**
     *
     * 获取参数md5签名
     *
     * @param array $params 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */
    public static function md5Sign(array $data,$secretKey)
    {
        unset($data['signType']);
        unset($data['sign']);
        unset($data['Sign']);
        unset($data['authCode']);
        $signParams = [];
        foreach ($data as $key => $value) {
            if($value=='') continue;
            $signParams[] = "$key=$value";
        }
        sort($signParams, SORT_STRING);
        $strToSign  = implode('&',$signParams).$secretKey;

        $sign = md5($strToSign);
        Yii::info('md5Sign string: '.$strToSign.' '.$sign);
        return $sign;

    }

}