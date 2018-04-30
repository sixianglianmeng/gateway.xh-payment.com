<?php

namespace app\lib\payment\channels\allscore;

use app\common\models\model\Remit;
use Yii;
use app\common\models\model\Order;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use app\lib\payment\ObjectNoticeResult;

class AllScoreBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 2;
    const  TRADE_STATUS_FAIL = 4;
    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
    }

    /*
     * 解析异步通知请求，返回订单
     *
     * return ObjectNoticeResult
     */
    public function parseNotifyRequest(array $request){
        //check sign

        //get order id from request
//        $orderId = $_REQUEST['orderId'];
//        //get order object and set order
//        $order = Order::findOne(['order_no'=>$orderId]);
//        $this->setOrder($order);
    }

    /*
     * 解析同步通知请求，返回订单
     * 返回订单对象表示请求验证成功且已经支付成功，可进行下一步业务
     * 返回int表示请求验证成功，订单未支付完成,int为订单在三方的状态
     * 其它表示错误
     *
     * return ObjectNoticeResult
     */
    public function parseReturnRequest(array $request){
        //notifyId, notifyTime, sign, outOrderId, merchantId
        $orderNo = ControllerParameterValidator::getRequestParam($request, 'outOrderId',null,Macro::CONST_PARAM_TYPE_ALNUM, '订单号错误！');
        $notifyId = ControllerParameterValidator::getRequestParam($request, 'notifyId',null,Macro::CONST_PARAM_TYPE_STRING, 'notifyId错误！',[3]);
        $notifyTime = ControllerParameterValidator::getRequestParam($request, 'notifyTime',null,Macro::CONST_PARAM_TYPE_STRING, 'notifyTime错误！',[3]);
        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);
        $merchantId = ControllerParameterValidator::getRequestParam($request, 'merchantId',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);

        $order = LogicOrder::getOrderByOrderNo($orderNo);
        $channelAccount = LogicOrder::getPaymentChannelAccount($order);
        $this->setPaymentConfig($order,$channelAccount);

        $ret = new ObjectNoticeResult();

        //check sign
        //计算得出通知验证结果
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_notify_rsa.class.php"));

        $allscoreNotify = new \AllscoreNotify($this->paymentConfig);
        $verifyResult = 1;//$allscoreNotify->verifyReturn($request);
//http://dev.gateway.payment.com/gateway/allscore/return?outOrderId=P18042621133930266&notifyId=notifyId&notifyTime=notifyTime&sign=sign&merchantId=merchantId&tradeStatus=2&transAmt=1000&localOrderId=1111111
        if($verifyResult) {//验证成功
            //2表示交易成功，4表示交易失败,其他状态按“处理中”处理
            if(!empty($request['tradeStatus']) && $request['tradeStatus'] == self::TRADE_STATUS_SUCCESS) {
                $ret->order = $order;
                $ret->orderNo = $order->order_no;
                $ret->amount = $request['transAmt'];
                $ret->status = Macro::SUCCESS;
                $ret->channelOrderNo = $request['localOrderId'];
            }
            elseif(!empty($request['tradeStatus']) && $request['tradeStatus'] == self::TRADE_STATUS_FAIL) {
                $ret->status =  Macro::FAIL;
            }
            else{
                $ret->status =  Macro::ERR_PAYMENT_PROCESSING;
            }
            return $ret;
        }else{
            throw new SignatureNotMatchException("RSA签名验证失败");
        }
    }

    public function remit(){
//var_dump($this->remit);
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));

        $remit = $this->remit;
        $needParams = ['merchant_code', 'trade_no', 'order_amount', 'order_time', 'bank_code', ' account_name', 'account_number', 'sign'];

        // 必填参数//
        $outOrderId = $remit['order_no'];//商户网站订单（也就是外部订单号，是通过客户网站传给商银信系统，不可以重复）
        $service = "agentpay"; // 代付支付服务（不可以修改）
        $inputCharset = trim($this->paymentConfig['input_charset']); // （不可以修改）
        $merchantId = $remit['channel_merchant_id']; // 商户号(商银信公司提供)
        $cardHolder = $remit['bank_account'];//收款人姓名
        $bankCardNo = $remit['bank_no'];//收款人银行卡号
        $notifyUrl = '';//$remit['notifyUrl']; // 通知接收URL(本地测试时，服务器返回无法测试)
        $bankBranchName = $remit['bank_name'];//银行具体名称
        $payAmount = $remit['amount'];//需要代付的金额
        $bankCode = $remit['bank_code'];//银行编码

        $format = 'json'; //返回格式（json/xml）

        $signType = 'RSA';//签名类型
        $payMethod = 'singleAgentPay';//默认支付方式

        $serialNo = '1';//代付记录序号

        $bankName = '';//$remit['bankName'];//收款人银行账号开户行
        $bankProvince = '';//$remit['bankProvince'];//开户所在省
        $bankCity = '';//$remit['bankCity'];////开户所在市

        $subject = 'remit';//用途
        $cardAccountType = 1;//卡账户类型 1个人2企业
        $remark = '';//备注信息

        //构造要请求的参数数组
        $parameter = array(
            "service" => $service,
            "merchantId" => $merchantId,
            "format" => $format,
            "notifyUrl" => $notifyUrl,
            "signType" => $signType,
            "inputCharset" => $inputCharset,
            "payMethod" => $payMethod,
            "outOrderId" => $outOrderId,
            "serialNo" => $serialNo,
            "cardHolder" => $cardHolder,
            "bankCardNo" => $bankCardNo,
            "bankName" => $bankName,
            "bankProvince" => $bankProvince,
            "payAmount" => $payAmount,
            "bankCity" => $bankCity,
            "bankBranchName" => $bankBranchName,
            "bankCode" => $bankCode,
            "subject" => $subject,
            "cardAccountType" => $cardAccountType,
            "remark" => $remark
        );
        /*logResult("parameter1=".print_r($parameter,1));
        $parameter['bankName'] = urldecode($parameter['bankName']);
        $parameter['bankProvince'] = urldecode($parameter['bankProvince']);
        $parameter['cardHolder'] = urldecode($parameter['cardHolder']);
        $parameter['notifyUrl'] = urldecode($parameter['notifyUrl']);
        $parameter['remark'] = urldecode($parameter['remark']);
        $parameter['subject'] = urldecode($parameter['subject']);
        logResult("parameter2=".print_r($parameter,1));*/
        // 构造代扣支付接口
        $allscoreService = new \AllscoreService($this->paymentConfig);
        $resTxt = $allscoreService->payment($parameter);
        $ret = Macro::FAILED_MESSAGE;
        if(!empty($resTxt)){
            $res = json_decode($resTxt,true);
            if(isset($res['retCode']) && $res['retCode']=='0000'){
                $ret = Macro::SUCCESS_MESSAGE;
                $ret['data'] = $res;
            }else{
                $ret['data'] = $res;
                $ret['message'] = $res['retMsg'];
            }
        }

        return  $ret;
    }

    public function remitStatus(){
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));

// 必填参数//
        $service = "agentpay"; // 代付查询服务（不可以修改）
        $merchantId = $this->remit['channel_merchant_id']; // 商户号(商银信公司提供)
        $format = 'json'; //返回格式（json/xml）
        $signType = 'RSA';//签名类型
        $inputCharset = trim($this->paymentConfig['input_charset']); // 参数编码字符集（不可以修改）
        $outOrderId = $this->remit['order_no'];//商户网站订单（也就是外部订单号，是通过客户网站传给商银信系统，不可以重复）

        $key = trim($this->paymentConfig['key']); // 安全密钥(商银信公司提供)
        //构造要请求的参数数组
        $parameter = array(
            "service" => $service,
            "merchantId" => $merchantId,
            "format" => $format,
            "signType" => $signType,
            "inputCharset" => $inputCharset,
            "outOrderId" => $outOrderId,
            //"version" => "1",
        );
        /*logResult("parameter1=".print_r($parameter,1));
        $parameter['bankName'] = urldecode($parameter['bankName']);
        $parameter['bankProvince'] = urldecode($parameter['bankProvince']);
        $parameter['cardHolder'] = urldecode($parameter['cardHolder']);
        $parameter['notifyUrl'] = urldecode($parameter['notifyUrl']);
        $parameter['remark'] = urldecode($parameter['remark']);
        $parameter['subject'] = urldecode($parameter['subject']);
        logResult("parameter2=".print_r($parameter,1));*/
        // 构造代扣支付接口
//        $allscoreService = new \AllscoreService($allscore_config);
//        $html_text = $allscoreService->paymentQuery($parameter);
//        //logResult("html_text=".$html_text);
//        echo $html_text;

        $resTxt = \AllscoreService::quickPost($this->paymentConfig['payment_query_url'],$parameter,$this->paymentConfig);
        $ret = Macro::FAILED_MESSAGE;
        if(!empty($resTxt)){
            $res = json_decode($resTxt,true);
            if(isset($res['retCode']) && $res['retCode']=='0000'){
                $ret = Macro::SUCCESS_MESSAGE;
                $ret['data'] = $res;
            }elseif(isset($res['retCode']) && $res['retCode']=='0000'){
                $ret = Macro::SUCCESS_MESSAGE;
                $ret['data'] = $res;
            }else{
                $ret['data'] = $res;
                $ret['message'] = $res['retMsg'];
            }
        }

        return  $ret;
    }
}