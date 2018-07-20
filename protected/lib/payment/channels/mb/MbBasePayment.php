<?php

namespace app\lib\payment\channels\mb;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;
use app\common\models\model\Order;
use Yii;

/**
 * 摩宝网银快捷接口
 *
 * @package app\lib\payment\channels\mb
 */
class MbBasePayment extends BasePayment
{
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
        $callbackParamsName = [
            'versionId',
            'businessType',
            'insCode',
            'merId',
            'transDate',
            'transAmount',
            'transCurrency',
            'transChanlName',
            'openBankName',
            'orderId',
            'payStatus',
            'payMsg',
            'pageNotifyUrl',
            'backNotifyUrl',
            'orderDesc',
            'dev',
            'signData',
        ];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['orderId']      = ControllerParameterValidator::getRequestParam($data, 'orderId', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['transAmount']  = ControllerParameterValidator::getRequestParam($request, 'transAmount', null, Macro::CONST_PARAM_TYPE_INT, '订单金额错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['orderId']);
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

//        $localSign = self::md5Sign($data, $this->paymentConfig['key']);
//        if($sign != $localSign){
//            throw new SignatureNotMatchException("签名验证失败");
//        }
        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;
        $ret['message'] = $data['payMsg'];
        if (!empty($data['payStatus']) && $data['payStatus'] == '00' && $data['transAmount']>0) {
            $ret['data']['transAmount'] = $data['transAmount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['trade_status'] = Order::STATUS_NOTPAY;
        }
        return $ret;
    }

    /*
     * 网银快捷支付
     * 对应文档的银行网关快捷PC支付（浏览器请求）
     *
     * return array ['url'=>'get跳转链接','formHtml'=>'自动提交的form表单HTML']
     */
    public function bankQuickPay()
    {
//        $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);
//        if(empty($bankCode)){
//            throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
//        }
        $params = [
            'versionId'                     => '001',
            'businessType'                  => '1100',
            'insCode'                       => '',
            'merId'                         => $this->order['channel_merchant_id'],
            'orderId'                       => $this->order['order_no'],
            'transDate'                     => date("YmdHis"),
            'transAmount'                   => $this->order['amount'],
            'transCurrency'                 => "156",
            'transChanlName'                => 'UNIONPAY',
            'openBankName'                  => '',
            'pageNotifyUrl'                 => str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/mb/return",
            'backNotifyUrl'                 => str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/mb/notify",
            'orderDesc'                     => '账户充值',
            'dev'                           =>''
        ];
        //备注：insCode, openBankName, orderDesc, dev 不参与签名！！！
        $signParams = $params;
//        $params['signType']='MD5';
        $params['signData'] = self::md5Sign($signParams,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/ks_netbank/mpay.c";
        $formTxt = self::buildForm($params,$requestUrl,false);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$this->order->merchant_id,
            'merchant_name'=>$this->order->merchant_account,
            'channel_account_id'=>$this->order->channelAccount->id,
            'channel_name'=>$this->order->channelAccount->channel_name,
        ];
        LogicApiRequestLog::outLog($requestUrl, 'POST', '', 200,0, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
//        $ret['data']['formHtml'] = $formTxt;
        $ret['data']['url'] = $requestUrl.'?'.http_build_query($params);
exit($ret['data']['url']);
        return $ret;
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'versionId'                 => '001',
            'businessType'              => '1300',
            'insCode'                   => '',
            'merId'                     => $this->order['channel_merchant_id'],
            'transDate'                 => date("YmdHis"),
            'orderId'                   => $this->order['order_no'],
        ];

        $signParams = $params;
        $params['signType']='MD5';
        $params['signData'] = self::md5Sign($signParams,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/ks_netbank/query.c";
        $resTxt = self::post($requestUrl,$params);

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = mb_convert_encoding($resTxt, "utf-8", "GBK");
            if (isset($res['payStatus']) && $res['payStatus'] == '00') {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                $ret['data']['url'] = $requestUrl;
            }
            if(isset($res['refCode'])){
                $ret['msg'] = $res['refMsg'];
            }
        }
        return $ret;
    }
    /**
     * 余额查询,此通道没有余额查询接口.但是需要做伪方法,防止批量实时查询失败.
     *
     * return  array BasePayment::BALANCE_QUERY_RESULT
     */
    public function balance()
    {
        return true;
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
//        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code'],'remit');
//
//        if(empty($bankCode)){
//            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
//        }
//
//        if(empty($this->remit['bank_branch'])){
////            throw new OperationFailureException("银行卡开户网点不能为空！",Macro::ERR_PAYMENT_BANK_CODE);
//            $this->remit['bank_branch'] = $this->remit['bank_name'].'北京市中关村分行';
//        }
        $transBody = [
            'orderId'               => $this->remit['order_no'],
            'transDate'             => date('YmdHis'),
            'transAmount'           => $this->remit['amount'],
            'accNo'                 => $this->remit['bank_no'],
            'accName'               => iconv('UTF-8','GBK',$this->remit['bank_account']),
        ];
        $params = [
            'versionId'             =>'001',
            'businessType'          =>'470000',
            'merId'                 =>$this->remit['channel_merchant_id'],
            'transBody'             =>$transBody,
            'dev'                   =>'',
        ];
        //备注：notify_url, variables 不参与签名！！！
        $signParams = $params;
        $params['signType'] = 'MD5';
        $params['signData'] = self::md5Sign($signParams, trim($this->paymentConfig['key']));

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
     *
     * 获取参数排序md5签名
     *
     * @param array $params 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */
    public static function md5Sign(array $params, string $signKey){
        $transStr="";
        if (is_array($params)) {
            $flag=1;
            foreach($params as $v=>$a)
            {
                if(sizeof($params)==$flag){
                    $transStr= $transStr.$v."=".$a;
                }else{
                    $transStr= $transStr.$v."=".$a."&";
                }
                $flag++;
            }
        } elseif (is_string($params)) {

        } else {
            return false;
        }
        $signStr = strtoupper(md5($transStr.$signKey,false));
        Yii::info('md5Sign string: '.$signStr.' raw: '.$transStr.''.$signKey);
        return $signStr;
    }
}