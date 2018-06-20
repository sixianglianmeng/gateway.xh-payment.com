<?php

namespace app\lib\payment\channels\mf;

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
use Yii;

/**
 * 密付支付接口
 *
 * @package app\lib\payment\channels\mf
 */
class MfBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_WECHAT_QR   => 'WECHAT',
        Channel::METHOD_ALIPAY_QR   => 'ALIPAY',
        Channel::METHOD_QQWALLET_QR => 'QQ',
        Channel::METHOD_UNIONPAY_QR => 'UNION',
        Channel::METHOD_WECHAT_H5   => 'H5_WECHAT',
        Channel::METHOD_ALIPAY_H5   => 'H5_ALIPAY',
        Channel::METHOD_QQ_H5       => 'H5_QQ',
        Channel::METHOD_UNIONPAY_H5 => 'H5_UNION',
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
        $callbackParamsName = ['code','merchant','bank','status','billno','amount','pay_time','msg','sign','sign_type'];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['billno']      = ControllerParameterValidator::getRequestParam($data, 'billno', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount']  = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_INT, '订单金额错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['billno']);
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
            throw new SignatureNotMatchException("签名验证失败");
        }


        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;
        $ret['message'] = $data['msg'];

        if (!empty($data['code']) && $data['code'] == '1000'
            && !empty($data['status']) && $data['status'] == '110'
            && $data['amount']>0
        ) {
            $ret['data']['amount'] = $data['amount'];
            $ret['status'] = Macro::SUCCESS;
        }
        return $ret;
    }

    /**
     * 网银支付
     */
    public function webBank()
    {

        if($this->order['pay_method_code']==Channel::METHOD_WEBBANK){
            $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

            if(empty($bankCode)){
                throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
            }
        }
        elseif(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'merchant'=>$this->order['channel_merchant_id'],
            'billno'=>$this->order['order_no'],
            'amount'=>$this->order['amount'],
            'notify_url'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/mf/notify",
            'sign_type'=>'MD5',
            'bank'=>$bankCode,
            'pay_time'=>date('YmdHis'),
        ];
        //备注：notify_url, return_url, device, variables 不参与签名！！！
        $signParams = $params;
        unset($signParams['notify_url']);
        unset($signParams['return_url']);
        unset($signParams['device']);
        unset($signParams['variables']);
        $params['sign'] = self::md5Sign($signParams,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/api/pay";

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, 'redirect', $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['formHtml'] = self::buildForm($params,$requestUrl);

        return $ret;
    }

    /**
     * 微信扫码支付
     */
    public function wechatQr()
    {
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'merchant'=>$this->order['channel_merchant_id'],
            'billno'=>$this->order['order_no'],
            'amount'=>$this->order['amount'],
            'notify_url'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/mf/notify",
            'sign_type'=>'MD5',
            'bank'=>$bankCode,
            'pay_time'=>date('YmdHis'),
        ];
        //备注：notify_url, return_url, device, variables 不参与签名！！！
        $signParams = $params;
        unset($signParams['notify_url']);
        unset($signParams['return_url']);
        unset($signParams['device']);
        unset($signParams['variables']);
        $params['sign'] = self::md5Sign($signParams,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/api/pay";
        $resTxt = self::post($requestUrl,$params);

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (isset($res['code']) && $res['code'] == '1000'
                && !empty($res['qrCode'])
            ) {
                $ret['status'] = Macro::SUCCESS;
                //
                if(Util::isMobileDevice() && substr($res['qrCode'],0,4)=='http'){
                    $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                    $ret['data']['url'] = $res['qrCode'];
                }else{
                    $ret['data']['type'] = self::RENDER_TYPE_QR;
                    $ret['data']['qr'] = $res['qrCode'];
                }
            } else {
                $ret['message'] = $res['msg']??'付款提交失败';
            }
        }

        return $ret;
    }

    /**
     * 微信H5支付
     */
    public function wechatH5()
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

    /**
     * 支付宝H5支付
     */
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

    /**
     * QQ H5支付
     */
    public function qqH5()
    {
        return $this->wechatQr();
    }

    /**
     * 银联扫码支付
     */
    public function unoinPayQr()
    {
        return $this->webBank();
    }

    /**
     * 银联H5支付
     */
    public function unionPayH5()
    {
        return $this->webBank();
    }


    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'merchant'=>$this->order['channel_merchant_id'],
            'billno'=>$this->order['order_no'],
            'amount'=>ceil($this->order['amount']),
            'sign_type'=>'MD5',
            'pay_time'=>date('YmdHis'),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/query";
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['code']) && $res['code'] == '1000'
                && isset($res['status']) && isset($res['status'])=='110'
                && !empty($res['amount'])
            ) {
                $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                if($localSign == $res['data']['sign']){
                    $ret['status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['amount'];
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
            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'merchant'=>$this->remit['channel_merchant_id'],
            'billno'=>$this->remit['order_no'],
            'amount'=>$this->remit['amount'],
            'notify_url'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/mf/remit-notify",
            'sign_type'=>'MD5',
            'passwd'=>md5(trim($this->paymentConfig['remit_key'])),
            'bank'=>$bankCode,
            'amount'=>$this->remit['amount'],
            'bank_site_name'=>$this->remit['bank_branch'],
            'bank_account_no'=>$this->remit['bank_no'],
            'bank_account_name'=>$this->remit['bank_account'],
            'request_time'=>date('YmdHis'),
        ];
        //备注：notify_url, variables 不参与签名！！！
        $signParams = $params;
        unset($signParams['notify_url']);
        unset($signParams['variables']);
        $params['sign'] = self::md5Sign($signParams, trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/api/withdrawal';
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit to bank raw result: '.$this->remit['order_no'].' '.$resTxt);

        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
            Yii::info($this->remit['order_no'].'remit ret localSign '.$localSign.' remote sign:'.$res['sign']);
            if (
                isset($res['code']) && $res['code'] == '1000'
                && isset($res['status'])
            ) {

                if($res['status'] == '210'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }else{
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = !empty($res['msg'])?Util::unicode2utf8($res['msg']):"出款提交失败({$resTxt})";
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = !empty($res['msg'])?Util::unicode2utf8($res['msg']):"出款提交失败({$resTxt})";
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
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }

        $params = [
            'merchant'=>$this->remit['channel_merchant_id'],
            'billno'=>$this->remit['order_no'],
            'amount'=>$this->remit['amount'],
            'sign_type'=>'MD5',
            'request_time'=>date('YmdHis'),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/api/withdrawal/query';
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;

        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
            Yii::info('remit query ret sign: '.$this->remit['order_no'].' local:'.$localSign.' back:'.$res['sign']);
            if (
                isset($res['code']) && $res['code'] == '1000'
                && isset($res['status'])
            ) {
                //200：初始状态，处理中 210:处理中;220：代付成功;230：代付失败（未确认），处理中状态处理;260：失败已退款;【220成功，260失败，其他状态处理中状态处理】 请参照注意事项6
                if($res['status'] == '220'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                }elseif($res['status'] == '260'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = $res['msg']??"出款失败({$resTxt})";
                }else{
                    if($res['status'] == '230'){
                        $ret['message'] = $res['msg']??"三方代付失败未确认({$resTxt})";
                    }
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??"出款查询失败({$resTxt})";;
            }
        }

        return  $ret;
    }

    /*
 * 解析出款异步通知请求，返回订单
 *
 * @return array self::RECHARGE_NOTIFY_RESULT
 */
    public function parseRemitNotifyRequest(array $request){

        //按照文档获取所有签名参数,某些渠道签名参数不固定,也可以直接获取所有request
        $callbackParamsName = ['code','merchant','bank','status','billno','amount','bank_site_name','bank_account_no','bank_account_name',
            'request_time','sign','sign_type'];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['billno'] = ControllerParameterValidator::getRequestParam($data, 'billno', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount'] = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_INT, '订单金额错误！');
        $sign           = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $remit = LogicRemit::getOrderByOrderNo($data['billno']);
        $this->setPaymentConfig($remit->channelAccount);
        $this->setRemit($remit);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY,
            'merchant_id'=>$remit->merchant_id,
            'merchant_name'=>$remit->merchant_account,
            'channel_account_id'=>$remit->channelAccount->id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];

        $localSign = self::md5Sign($request,trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_QUERY_RESULT;
        $ret['data']['remit'] = $remit;
        $ret['data']['order_no'] = $remit->order_no;

        if(!empty($request['code']) && $request['code'] =='1000' && !empty($request['status']) && $request['status'] =='220' && $data['amount']>0) {
            $ret['data']['amount']      = $data['amount'];
            $ret['status']              = Macro::SUCCESS;
            $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
        }elseif(!empty($request['code']) && $request['code'] =='1000' && !empty($request['status']) && $request['status'] =='260') {
            $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
        }

        return $ret;
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
    public static function post(string $url, array $postData, $header = [], $timeout = 5)
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
        if (is_array($params)) {
            unset($params['sign']);
            unset($params['notify_url']);
            unset($params['return_url']);
            unset($params['device']);
            unset($params['variables']);
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


}