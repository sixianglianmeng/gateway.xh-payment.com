<?php

namespace app\lib\payment\channels\ht;

use app\common\models\model\BankCodes;
use app\common\models\model\Remit;
use Yii;
use app\common\models\model\Order;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;

class HtBasePayment extends BasePayment
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
     * 生成支付跳转参数连接
     *
     * return array ['url'=>'get跳转链接','formHtml'=>'自动提交的form表单HTML']
     */
    public function createPaymentRedirectParams()
    {

        $banCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);
        if(empty($banCode)){
            throw new \app\common\exceptions\OperationFailureException("银行代码配置错误:".get_class($this).':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'notify_url'=>Yii::$app->request->hostInfo."/gateway/ht/notify",
            'return_url'=>Yii::$app->request->hostInfo."/gateway/ht/return",
            'bank_code'=>$banCode,
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>$this->order['pay_method_code'],
            'order_amount'=>$this->order['amount'],
            'req_referer'=>Yii::$app->request->referrer?Yii::$app->request->referrer:Yii::$app->request->getHostInfo().Yii::$app->request->url,
            'order_time'=>date("Y-m-d H:i:s"),
            'customer_ip'=>Yii::$app->request->remoteIP,
            'return_params'=>$this->order['order_no'],
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['base_gateway_url'].'/pay.html';

        $getUrl = $requestUrl.'?'.http_build_query($params);
        $form = self::buildForm($params, $requestUrl);

        $ret = self::RECHARGE_WEBBANK_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['url'] = $getUrl;
        $ret['data']['formHtml'] = $form;

        return $ret;
    }

    /*
     * 支付宝-微信后台下单
     */
    public function alipayWechatOrder()
    {
        $banCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);
        if(empty($banCode)){
            throw new \app\common\exceptions\OperationFailureException("银行代码配置错误:".get_class($this).':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'notify_url'=>Yii::$app->request->hostInfo."/gateway/ht/notify",
            'return_url'=>Yii::$app->request->hostInfo."/gateway/ht/return",
            'bank_code'=>$banCode,
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>$this->order['pay_method_code'],
            'order_amount'=>$this->order['amount'],
            'req_referer'=>Yii::$app->request->referrer?Yii::$app->request->referrer:Yii::$app->request->getHostInfo().Yii::$app->request->url,
            'order_time'=>date("Y-m-d H:i:s"),
            'customer_ip'=>Yii::$app->request->remoteIP,
            'return_params'=>$this->order['order_no'],
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['base_gateway_url'].'/pay.html';

        $ret = self::post($requestUrl,$params);

        $ret = self::RECHARGE_WEBBANK_RESULT;
        $ret['status'] = Macro::SUCCESS;
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
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
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
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['order_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = $res['bank_status'];
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
                $ret['message'] = $res['errror_msg']??'出款查询失败';
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
}