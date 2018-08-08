<?php

namespace app\lib\payment\channels\apay;

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
 * 密付支付接口
 *
 * @package app\lib\payment\channels\mf
 */
class ApayBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_WECHAT_QR   => 'WECHAT',
        Channel::METHOD_ALIPAY_QR   => 'ALIPAY',
        Channel::METHOD_ALIPAY_H5   => 'ALIPAY',
        Channel::METHOD_QQ_QR       => 'QQ',
        Channel::METHOD_UNIONPAY_QR => 'UNION_QR',
        Channel::METHOD_JD_QR => 'JD',
        Channel::METHOD_BANK_QUICK => 'QUICK_PAY',
        Channel::METHOD_BANK_TRANSFER => 'TRANSFER',
        Channel::METHOD_ALIPAY_TRANSFER=> 'ALIPAY_TRANSFER',
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
        $callbackParamsName = ['billno','amount','paid_amount','status','fee','merchant_billno','merchant_remark','bank','sign','sign_way'];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['merchant_billno']      = ControllerParameterValidator::getRequestParam($data, 'merchant_billno', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount']  = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['status']  = ControllerParameterValidator::getRequestParam($request, 'status', null, Macro::CONST_PARAM_TYPE_INT, '状态错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['merchant_billno']);
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

        if($data['status'] != '200'){
            throw new SignatureNotMatchException("订单状态错误");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;

        if (!empty($data['status']) && $data['status'] == '200'
            && $data['paid_amount']>0
        ) {
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['amount'] = $data['paid_amount'];
            $ret['data']['channel_order_no'] = $data['merchant_billno'];
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
            'merchant_no'=>$this->order['channel_merchant_id'],
            'amount'=>$this->order['amount'],
            'merchant_billno'=>$this->order['order_no'],
            'merchant_remark'=>'',
            'bank'=>$bankCode,
            'return_url'=>str_replace('http','http',$this->getRechargeReturnUrl()),
            'notify_url'=>str_replace('http','http',$this->getRechargeNotifyUrl()),
            'sign_way'=>'md5',
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/merchant/deposit";
        $resTxt = self::post($requestUrl,$params);

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (isset($res['code']) && $res['code'] == '0'
                && !empty($res['data']['deposit_url'])
            ) {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                $ret['data']['channel_order_no'] = $res['data']['billno'];
                $ret['data']['url'] = $res['data']['deposit_url'];
            } else {
                $ret['message'] = $res['msg']??'付款提交失败';
            }
        }

        return $ret;
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

    /**
     * 支付宝h5支付
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
     * 银联扫码支付
     */
    public function unoinPayQr()
    {
        return $this->wechatQr();
    }

    /**
     * 银行转账
     */
    public function bankTransfer()
    {
        return $this->wechatQr();
    }

    /**
     * 支付宝转账
     */
    public function alipayTransfer()
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
            'merchant_no'=>$this->order['channel_merchant_id'],
            'merchant_billno'=>$this->order['order_no'],
            'sign_way'=>'md5',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/merchant/deposit/query?".http_build_query($params);
        $resTxt = self::httpGet($requestUrl);

        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['code']) && $res['code'] == '0'
                && isset($res['data']['status'])
            ) {
                if(isset($res['data']['status'])=='200'
                    && !empty($res['data']['paid_amount'])){
                    $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                    if($localSign == $res['data']['sign']){
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['amount'] = $res['data']['paid_amount'];
                        $ret['data']['channel_order_no'] = $res['data']['billno'];
                        $ret['data']['trade_status'] = Order::STATUS_PAID;
                    }
                }
            } else {
                $ret['message'] = '订单查询失败:'.$resTxt;
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
            throw new OperationFailureException("通道APAY银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
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
            'merchant_no'=>$this->remit['channel_merchant_id'],
            'amount'=>$this->remit['amount'],
            'merchant_billno'=>$this->remit['order_no'],
            'merchant_remark'=>'',
            'bank'=>$bankCode,
            'notify_url'=>str_replace('http','http',$this->getRemitNotifyUrl()),
            'account_no'=>$this->remit['bank_no'],
            'account_username'=>$this->remit['bank_account'],
            'province'     => $this->remit['bank_province'],
            'city'         => $this->remit['bank_city'],
            'bank_branch'       => $this->remit['bank_branch'],
            'sign_way'=>'md5',
        ];

        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['remit_key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/merchant/withdrawal';
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit to bank raw result: '.$this->remit['order_no'].' '.$resTxt);

        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (
                isset($res['code']) && $res['code'] == '0'
                && isset($res['data']['status'])
            ) {
                $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                Yii::info($this->remit['order_no'].'remit ret localSign '.$localSign.' remote sign:'.$res['sign']);

                if($res['data']['status'] == '100'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }elseif($res['data']['status'] == '200'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                }elseif($res['data']['status'] == '300'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = "出款提交失败({$resTxt})";
                }

                $ret['data']['billno'] = $res['data']['billno'];
                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = "出款提交失败({$resTxt})";
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
            'merchant_no'=>$this->remit['channel_merchant_id'],
            'merchant_billno'=>$this->remit['order_no'],
            'sign_way'=>'md5',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['remit_key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/merchant/withdrawal/query?'.http_build_query($params);
        $resTxt = self::httpGet($requestUrl);
        LogicApiRequestLog::outLog($requestUrl, 'GET', $resTxt, 200,0, $params);

        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;

        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if($res){
                $localSign = self::md5Sign($res['data'],trim($this->paymentConfig['remit_key']));
                Yii::info('remit query ret sign: '.$this->remit['order_no'].' local:'.$localSign.' back:'.$res['data']['sign']);
                if (
                    $localSign == $res['data']['sign']
                    && isset($res['code']) && $res['code'] == '0'
                    && isset($res['data']['status'])
                ) {

                    if($res['data']['status'] == '100'){
                        $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                    }elseif($res['data']['status'] == '200'){
                        $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                        $ret['message'] = "出款提交失败({$resTxt})";
                    }elseif($res['data']['status'] == '300'){
                        $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                        $ret['message'] = "出款提交失败({$resTxt})";
                    }

                    $ret['data']['billno'] = $res['data']['billno'];
                    $ret['status'] = Macro::SUCCESS;
                } else {
                    $ret['message'] = "出款查询失败({$resTxt})";
                }
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
        $callbackParamsName = ['billno','amount','status','fee','merchant_billno','merchant_remark','bank','sign'];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['merchant_billno'] = ControllerParameterValidator::getRequestParam($data, 'merchant_billno', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount'] = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_INT, '订单金额错误！');
        $sign           = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $remit = LogicRemit::getOrderByOrderNo($data['merchant_billno']);
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
        //删除统一回调时添加的参数
        unset($request['channelId']);
        $localSign = self::md5Sign($request,trim($this->paymentConfig['remit_key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_QUERY_RESULT;
        $ret['data']['remit'] = $remit;
        $ret['data']['order_no'] = $remit->order_no;

        if(!empty($request['status']) && $request['status'] =='200' && $data['amount']>0) {
            $ret['data']['amount']      = $data['amount'];
            $ret['status']              = Macro::SUCCESS;
            $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
            $ret['data']['fee'] = $data['fee'];
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
        if (is_array($params)) {
            unset($params['sign']);
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

        $signStr = md5($params.''.$signKey);
        Yii::info('md5Sign string: '.$signStr.' raw: '.$params.''.$signKey);
        return $signStr;
    }


}