<?php

namespace app\lib\payment\channels\allscore;

use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Yii;

class AllScoreBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 2;
    const  TRADE_STATUS_FAIL = 4;
    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
    }

    /*
 * 生成支付跳转参数连接
 *
 * return array ['url'=>'','formHtml'=>'']
 */
    public function webBank()
    {
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));
        $service = "directPay"; // 快速付款交易服务（不可以修改）
        $inputCharset = trim($this->paymentConfig['input_charset']); // （不可以修改）
        $merchantId = $this->order['channel_merchant_id']; // 商户号(商银信公司提供)

        $key = trim($this->paymentConfig['key']); // 安全密钥(商银信公司提供)
        //商户网站订单
        $outOrderId = $this->order['order_no'];
        $subject = empty($this->order['title'])?'1':$this->order['title'];
        $body = empty($this->order['description'])?'1':$this->order['description'];
        $transAmt = $this->order['amount'];
        $notifyUrl = Yii::$app->request->hostInfo."/gateway/allscore/notify"; // 通知接收URL(本地测试时，服务器返回无法测试)
        $returnUrl = Yii::$app->request->hostInfo."/gateway/allscore/return"; // 支付完成后跳转返回的网址URL
        $detailUrl = '';

        //外部账户ID
        $outAcctId = $this->order['merchant_user_id'];

        $bankList = require Yii::getAlias("@app/config/payment/allscore/banks.php");
        if(empty($bankList[$this->order['bank_code']])){
            throw new \app\common\exceptions\OperationFailureException('银行代码配置错误:'.__LINE__,Macro::ERR_PAYMENT_BANK_CODE);
        }
        $defaultBank = $bankList[$this->order['bank_code']]['code'];//$this->order['bank_code'];

        $channel = 'B2C';//B2C个人，b2b企业网银
        $certType = 'debit';//只使用储蓄卡，credit信用卡
        $cardAttr = "01";//"02"信用卡
        $signType = 'RSA';

        $payMethod = 'bankPay';
        if ($payMethod == 'bankPay') {
            // 构造要请求的参数数组
            $parameter = array(
                "service" => $service, //
                "inputCharset" => $inputCharset, //
                "merchantId" => $merchantId, //
                "payMethod" => $payMethod, //

                "outOrderId" => $outOrderId, //
                "subject" => $subject, //
                "body" => $body, //
                "transAmt" => $transAmt, //

                "notifyUrl" => $notifyUrl, //
                "returnUrl" => $returnUrl, //

                "signType" => $signType,

                "defaultBank" => $defaultBank,

                "channel" => $channel,
                "cardAttr" => $cardAttr
            );

            // 构造网银支付接口
            $allscoreService = new \AllscoreService($this->paymentConfig);
            $html_text = $allscoreService->bankPay($parameter);
            $ItemUrl = $allscoreService->createBankUrl($parameter);
        }
        //快捷支付
        else {

            $parameter = array(
                "service" => $service, //
                "inputCharset" => $inputCharset, //
                "merchantId" => $merchantId, //
                "payMethod" => $payMethod, //

                "outOrderId" => $outOrderId, //
                "subject" => $subject, //
                "body" => $body, //
                "transAmt" => $transAmt, //

                "notifyUrl" => $notifyUrl, //
                "returnUrl" => $returnUrl, //

                "signType" => $signType, //

                "outAcctId" => $outAcctId,
                "cardType" => $certType
            );

            // 构造快捷支付接口
            $allscoreService = new \AllscoreService($this->paymentConfig);
            $html_text = $allscoreService->quickPay($parameter);
            $ItemUrl = $allscoreService->createQuickUrl($parameter);
        }

        $ret = self::RECHARGE_WEBBANK_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['url'] = $ItemUrl;
        $ret['data']['formHtml'] = $html_text;

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
        //notifyId, notifyTime, sign, outOrderId, merchantId
        $orderNo = ControllerParameterValidator::getRequestParam($request, 'outOrderId',null,Macro::CONST_PARAM_TYPE_ALNUM, '订单号错误！');
        $notifyId = ControllerParameterValidator::getRequestParam($request, 'notifyId',null,Macro::CONST_PARAM_TYPE_STRING, 'notifyId错误！',[3]);
        $notifyTime = ControllerParameterValidator::getRequestParam($request, 'notifyTime',null,Macro::CONST_PARAM_TYPE_STRING, 'notifyTime错误！',[3]);
        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);
        $merchantId = ControllerParameterValidator::getRequestParam($request, 'merchantId',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);

        $order = LogicOrder::getOrderByOrderNo($orderNo);
        $this->setPaymentConfig($order->channelAccount);
        $this->setOrder($order);

        $ret = self::RECHARGE_NOTIFY_RESULT;

        //check sign
        //计算得出通知验证结果
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_notify_rsa.class.php"));

        $allscoreNotify = new \AllscoreNotify($this->paymentConfig);
        $verifyResult = $allscoreNotify->verifyReturn($request);
        //TODO chec payment callback rsa
        if(YII_DEBUG){
            //$verifyResult = 1;
        }
//http://dev.gateway.payment.com/gateway/v1/web/allscore/return?outOrderId=P18042621133930266&notifyId=notifyId&notifyTime=notifyTime&sign=sign&merchantId=merchantId&tradeStatus=2&transAmt=1000&localOrderId=1111111
        if($verifyResult) {//验证成功
            //2表示交易成功，4表示交易失败,其他状态按“处理中”处理
            $ret = self::RECHARGE_NOTIFY_RESULT;
            if(!empty($request['tradeStatus']) && $request['tradeStatus'] == self::TRADE_STATUS_SUCCESS) {
                $ret['data']['order'] = $order;
                $ret['data']['order_no'] = $order->order_no;
                $ret['data']['amount'] = $request['transAmt'];
                $ret['data']['trade_status'] = Order::STATUS_NOTPAY;
                $ret['data']['channel_order_no'] = $request['localOrderId'];
            }
            elseif(!empty($request['trade_status']) && $request['trade_status'] == self::TRADE_STATUS_FAIL) {
                $ret['status'] =  Macro::FAIL;
            }
            else{
                $ret['status'] =  Macro::ERR_PAYMENT_PROCESSING;
            }

            return $ret;
        }else{
            throw new SignatureNotMatchException("RSA签名验证失败");
        }
    }

    public function remit(){
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

        // 构造代扣支付接口
        $allscoreService = new \AllscoreService($this->paymentConfig);
//        $resTxt = $allscoreService->payment($parameter);
        //TODO 取消测试数据构造，恢复正常提交
        if(YII_DEBUG && YII_ENV == YII_ENV_DEV) {
            $resTxt='{"merchantId": "001015013101118","orderId": "20160618155020329942","outOrderId": "20160618155640950","retCode": "0000","retMsg": "操作完成","status": "00","transTime": "2016-06-18 15:52:43"}';
            $res = json_decode($resTxt,true);
        }

        $ret = self::REMIT_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if(isset($res['retCode']) && $res['retCode']=='0000'){
                switch ($res['status']){
                    case '00':
                        $ret['status']         = Macro::SUCCESS;
                        $ret['data']['bank_status'] =  Remit::BANK_STATUS_PROCESSING;
                        break;
                    case '04':
                        $ret['status']         = Macro::SUCCESS;
                        $ret['data']['bank_status'] =  Remit::BANK_STATUS_SUCCESS;
                        break;
                    case '05':
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['bank_status'] =  Remit::BANK_STATUS_FAIL;
                        break;
                }

                if(!empty($res['orderId'])){
                    $ret['data']['channel_order_no'] = $res['orderId'];
                }
            }
        }

        return  $ret;
    }

    public function remitStatus(){
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));

        //必填参数//
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

        $resTxt = \AllscoreService::quickPost($this->paymentConfig['payment_query_url'],$parameter,$this->paymentConfig);
        $ret = self::REMIT_QUERY_RESULT;

        //记录请求日志
        LogicApiRequestLog::outLog($this->paymentConfig['payment_query_url'], 'POST', $resTxt, 200,0, $parameter);

        if(!empty($resTxt)){
            $res = json_decode($resTxt,true);
            if(isset($res['retCode']) && $res['retCode']=='0000'){
                switch ($res['status']){
                    case '00':
                        $ret['status']         = Macro::SUCCESS;
                        $ret['data']['bank_status'] =  Remit::BANK_STATUS_PROCESSING;
                    case '04':
                        $ret['status']         = Macro::SUCCESS;
                        $ret['data']['bank_status'] =  Remit::BANK_STATUS_SUCCESS;
                    case '05':
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['bank_status'] =  Remit::BANK_STATUS_FAIL;
                }

                if(!empty($res['orderId'])){
                    $ret['data']['channel_order_no'] = $res['orderId'];
                }
            }
        }

        return  $ret;
    }

    public function orderStatus(){
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));

        //构造要请求的参数数组
        $parameter = array(
            "service" => 'orderQuery',
            "merchantId" => $this->order['channel_merchant_id'],
            "format" => 'json',
            "signType" => 'RSA',
            "inputCharset" => trim($this->paymentConfig['input_charset']),
            "outOrderId" => $this->order['order_no'],
            //"version" => "1",
        );

        $resTxt = \AllscoreService::quickPost($this->paymentConfig['payment_query_url'],$parameter,$this->paymentConfig);
        $ret = Macro::FAILED_MESSAGE;
        if(!empty($resTxt)){
            $res = json_decode($resTxt,true);
            if(isset($res['retCode']) && $res['retCode']=='0000'){
                throw new \app\common\exceptions\OperationFailureException('订单查询返回: '.$resTxt);
                $ret = Macro::SUCCESS_MESSAGE;
                if($res['trade_status'] == Macro::SUCCESS){
                    $ret['data']['trade_status'] = Order::STATUS_PAID;
                }
            }else{
                $ret['data'] = $resTxt;
                $ret['message'] = $res['retMsg'];
            }
        }

        return  $ret;
    }

    /*
     * 查询余额
     */
    public function balance(){
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));

        //构造要请求的参数数组
        $parameter = array(
            "service" => 'agentpay',
            "merchantId" => $this->order['channel_merchant_id'],
            "format" => 'json',
            "signType" => 'RSA',
            "inputCharset" => trim($this->paymentConfig['input_charset']),
            "outOrderId" => $this->order['order_no'],
            //"version" => "1",
        );

        $resTxt = \AllscoreService::quickPost($this->paymentConfig['balance_query_url'],$parameter,$this->paymentConfig);

        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if(isset($res['retCode']) && $res['retCode']=='0000'){
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['balance'] = $res['availBalAmt'];
            } else {
                $ret['message'] = $res['retMsg']??'操作失败';
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