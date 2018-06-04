<?php

namespace app\lib\payment\channels\ht;

use app\common\models\model\BankCodes;
use app\common\models\model\Channel;
use app\common\models\model\Remit;
use app\components\Util;
use Yii;
use app\common\models\model\Order;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 恒星闪付接口
 * @package app\lib\payment\channels\ht
 */
class HxBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 'success';
    const  TRADE_STATUS_PROCESSING = 'paying';
    const  TRADE_STATUS_FAIL = 'failed';
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_WECHAT_QUICK_QR => 'qqrDynamicQR',
        Channel::METHOD_UNIONPAY_QR     => 'unionPayQR',
        Channel::METHOD_QQWALLET_QR     => 'tencentQQ',
        Channel::METHOD_WECHAT_QR       => 'wechatQR',
        Channel::METHOD_ALIPAY_QR       => 'aliPayQR',
        Channel::METHOD_UNIONPAY_H5     => 'unionQuickH5',
        Channel::METHOD_QQ_H5           => 'tencentQQH5',
        Channel::METHOD_WECHAT_H5       => 'wechatH5',
        Channel::METHOD_ALIPAY_H5       => 'alipayH5',
        Channel::METHOD_JD_H5           => 'jdH5',
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

        $data['order_no'] = ControllerParameterValidator::getRequestParam($request, 'order_no',null,Macro::CONST_PARAM_TYPE_ALNUM, '订单号错误！');
        $data['order_amount'] = ControllerParameterValidator::getRequestParam($request, 'order_amount',null,Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['order_time'] = ControllerParameterValidator::getRequestParam($request, 'order_time',null,Macro::CONST_PARAM_TYPE_STRING, '订单时间错误！',[3]);
        $data['return_params'] = ControllerParameterValidator::getRequestParam($request, 'notifyTime','',Macro::CONST_PARAM_TYPE_STRING, '订单返回参数错误！',[3]);
        $data['merchant_code'] = ControllerParameterValidator::getRequestParam($request, 'merchant_code',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);
        $data['trade_no'] = ControllerParameterValidator::getRequestParam($request, 'trade_no',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单号错误！',[3]);
        $data['trade_time'] = ControllerParameterValidator::getRequestParam($request, 'trade_time',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单时间错误！',[3]);
        $data['trade_status'] = ControllerParameterValidator::getRequestParam($request, 'trade_status',null,Macro::CONST_PARAM_TYPE_STRING, '状态错误！',[3]);
        $data['notify_type'] = ControllerParameterValidator::getRequestParam($request, 'notify_type',null,Macro::CONST_PARAM_TYPE_STRING, '通知类型错误！',[3]);
        $data['merchant_code'] = ControllerParameterValidator::getRequestParam($request, 'merchant_code',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);

        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);

        $order = LogicOrder::getOrderByOrderNo($data['order_no']);
        $this->setPaymentConfig($order->channelAccount);
        $this->setOrder($order);

        $localSign = self::md5Sign($data,trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(!empty($request['trade_status']) && $request['tradeStatus'] == self::TRADE_STATUS_SUCCESS) {
            $ret['order'] = $order;
            $ret['order_no'] = $order->order_no;
            $ret['amount'] = $data['order_amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['channel_order_no'] = $data['trade_no'];
        }
        elseif(!empty($request['trade_status']) && $request['trade_status'] == self::TRADE_STATUS_FAIL) {
            $ret['status'] =  Macro::FAIL;
        }
        else{
            $ret['status'] =  Macro::ERR_PAYMENT_PROCESSING;
        }

        return $ret;
    }

    /*
     * 微信H5支付
     */
    public function wechatH5()
    {

        $banCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);
        if(empty($banCode)){
            throw new \app\common\exceptions\OperationFailureException("银行代码配置错误:".get_class($this).':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'charset'=>'UTF-8',
            'version'=>'1.0',
            'signType'=>'MD5',
            'businessType'=>$this->getPayType($this->order['pay_method_code']),
            'backNotifyUrl'=>Yii::$app->request->hostInfo."/gateway/hx/notify",
            'pageNotifyUrl'=>Yii::$app->request->hostInfo."/gateway/hx/return",
            'merchantId'=>$this->order['channel_merchant_id'],
            'orderId'=>$this->order['order_no'],
            'tranAmt'=>$this->order['amount'],
            'tranTime'=>date("YmdHis"),
            'orderDesc'=>'',
            'return_params'=>$this->order['order_no'],
        ];

        $params['signData'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['base_gateway_url'].'/mpsGate/h5mpsTransaction';
var_dump($params);
        $headers = [
            ['Content-Type' => 'application/text;charset=UTF-8']
        ];
        $ret = self::post($requestUrl,$params);
var_dump(htmlspecialchars($ret));
exit;
        $ret = self::RECHARGE_WEBBANK_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_QR;
        $ret['data']['url'] = '';

        return $ret;
    }


    /**
     * 提交出款请求
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remit(){
        if(empty($this->remit)){
            throw new \app\common\exceptions\OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $params = [
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'trade_no'=>$this->remit['order_no'],
            'order_amount'=>$this->remit['amount'],
            'order_time'=>date("Y-m-d H:i:s"),
            'account_name'=>$this->remit['bank_account'],
            'account_number'=>$this->remit['bank_no'],
            'bank_code'=>$this->remit['bank_code'],
        ];
        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['base_gateway_url'] . '/remit.html';
        $resTxt = self::post($requestUrl, $params);

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
                $ret['message'] = $res['errror_msg']??'出款提交失败';
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
        $requestUrl = $this->paymentConfig['base_gateway_url'].'/remit_query.html';
        $resTxt = self::post($requestUrl, $params);

        $ret = self::REMIT_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success'])) $ret['status'] = Macro::SUCCESS;
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['data']['channel_order_no'] = $res['order_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = $res['bank_status'];
                if($res['bank_status']==3){
                    $ret['message'] = $res['errror_msg']??'银行处理失败';
                }
            } else {
                $ret['message'] = $res['errror_msg']??'出款查询失败';
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

        $requestUrl = $this->paymentConfig['base_gateway_url'].'/query.html';
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

        $requestUrl = $this->paymentConfig['base_gateway_url'].'/balance.html';
        $resTxt = self::post($requestUrl, $params);

        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['is_success']) && strtoupper($res['is_success'])== 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['balance'] = $res['money'];
                $ret['data']['frozen_balance'] = $res['freeze_money'];
            } else {
                $ret['message'] = $res['errror_msg']??'出款查询失败';
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
     * 根据平台支付类型获取恒星对应的支付类型字符串
     *
     * @param string $type 平台支付类型
     *
     * @return mixed|string
     */
    public static function getPayType($type)
    {
        if(empty(self::PAY_TYPE_MAPS[$type])){
            Util::throwException(Macro::ERR_PAYMENT_CHANNEL_TYPE_NOT_ALLOWED);
        }

        return self::PAY_TYPE_MAPS[$type] ?? '';
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
    public static function md5Sign(array $params, $signKey){
        if (is_array($params)) {
            unset($params['signData']);
            unset($params['signType']);
            $a      = $params;
            $params = array();
            foreach ($a as $key => $value) {
                $params[] = "$key=$value";
            }
            sort($params,SORT_STRING);
            $params = implode('&', $params);
        } else {
            return '';
        }

        $signStr = md5($params.'&'.$signKey);
        //        Yii::info(['md5Sign string: ',$signStr,$params]);
        return $signStr;
    }
}