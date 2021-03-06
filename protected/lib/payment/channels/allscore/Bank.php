<?php
namespace app\lib\payment\channels\allscore;

use app\components\Macro;
use Yii;
/*
 * 商银信银行卡支付
 */
class Bank extends AllScoreBasePayment
{
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


}