<?php
namespace app\lib\payment\channels\allscore;

use app\components\Macro;
use Yii;
/*
 * 商银信银行卡支付
 */
class Bank extends AllScoreBase
{
    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
    }

    /*
     * 生成支付跳转参数连接
     *
     * return array ['gatewayUrl'=>'','requestData'=>[],'requestMethod'=>'post']
     */
    public function createPaymentRedirectParams()
    {
//        parent::createPaymentRedirectParams($order, $channelAccount);


        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_service.class.php"));

//op_uid,op_username,order_no,merchant_order_no,channel_order_no,merchant_id,app_id,app_name,merchant_account,amount,paid_amount,channel_id,channel_merchant_id,pay_method_code,sub_pay_method_code,title,notify_status,notify_url,reutrn_url,client_ip,created_at,paid_at,updated_at,bak,notify_at,notify_times,next_notify_time,status,return_params

// 必填参数//
// $paygateway = "http://192.168.8.98:8088/webpay/serviceDirect.htm?";//支付接口（不可以修改）
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

        $this->paymentConfig['bankList'];
        if(empty($this->paymentConfig['bankList'][$this->order['bank_code']])){
            throw new \Exception('银行代码配置错误:'.__LINE__,Macro::ERR_PAYMENT_BANK_CODE);
        }
        $defaultBank = $this->paymentConfig['bankList'][$this->order['bank_code']]['code'];//$this->order['bank_code'];

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
            return $ItemUrl;
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
            return $ItemUrl;
        }


    }
}