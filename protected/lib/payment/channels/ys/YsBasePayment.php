<?php

namespace app\lib\payment\channels\ys;

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
use Yii;

/**
 * 银盛支付接口
 *
 * @package app\lib\payment\channels\ys
 */
class YsBasePayment extends BasePayment
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
        $callbackParamsName = ['sign_type','notify_type','notify_time','out_trade_no','trade_no','trade_status','total_amount','account_date'];
        $data = '';
        foreach ($callbackParamsName as $p){
            if(isset($request[$p])){
                $data[$p] = $request[$p];
            }
        }

        //验证必要参数
        $data['out_trade_no']      = ControllerParameterValidator::getRequestParam($data, 'out_trade_no', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['total_amount']  = ControllerParameterValidator::getRequestParam($request, 'total_amount', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['out_trade_no']);
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

        $localSign = self::rsaVerify(implode($data), $sign, $this->paymentConfig['rsaPrivateKey']);
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(!empty($request['trade_status']) && $request['trade_status'] == 'TRADE_SUCCESS' && $data['total_amount']>0) {
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['total_amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['channel_order_no'] = $data['trade_no'];
        }

        return $ret;
    }

    /*
     * 网银支付
     * 对应文档的银行网关快捷PC支付（浏览器请求）
     *
     * return array ['url'=>'get跳转链接','formHtml'=>'自动提交的form表单HTML']
     */
    public function webBank()
    {

        $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params['method'] = 'ysepay.online.directpay.createbyuser';
        $params['partner_id'] = $this->order['channel_merchant_id'];
        $params['timestamp'] = date('Y-m-d H:i:s');
        $params['charset'] = 'utf-8';
        $params['sign_type'] = 'RSA';
        $params['notify_url'] = $this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ys/notify";
        $params['return_url'] = $this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ys/return";
        $params['version'] = '3.0';
        $params['out_trade_no'] = $this->order['order_no'];
        $params['subject'] = '账户充值';
        $params['total_amount'] = bcadd(0, $this->order['amount'], 2);
        $params['seller_id'] = $this->order['channel_merchant_id'];
        $params['seller_name'] = $this->paymentConfig['seller_name'];
        $params['timeout_express'] = '3d';
        $params['business_code'] = '';
//        $params['currency'] = 'CNY';
//        $params['extend_params'] = '';
//        $params['extra_common_param'] = '';
//        $params['sub_merchant'] = '';
        $params['sign'] =self::rsaPrivateEncrypt(implode($params), $this->paymentConfig['rsaPrivateKey']);


        $requestUrl = $this->paymentConfig['gateway_base_uri'];

        $formTxt = self::buildForm($params,$requestUrl);

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
        $ret['data']['formHtml'] = $formTxt;

        return $ret;
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'payKey'=>$this->paymentConfig['payKey'],
            'merchantNo'=>$this->order['channel_merchant_id'],
            'orderNo'=>$this->order['order_no'],
            'serviceType'=>'Q001',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code'])
                && isset($res['isPaid'])
            ) {
                //交易状态  SUCCESS 打款成功 UNKNOW  未知的结果， 请继续轮询 FAILED 打款失败
                //注意：任何未明确返回tradeStatus状态为FAILED都不能认为失败！！！
                if($res['code'] == '00000' && $res['isPaid']=='YES' && $res['orderPrice']>0){
                    $ret['data']['trade_status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['orderPrice'];
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??'订单查询失败';
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
        $str = 'FAIL';
        if($isSuccess){
            $str = 'SUCCESS';
        }
        return $str;
    }

    /**
     * 获取支付下一跳的地址/post表单
     *
     * @param string $url
     * @param $postParams
     * return array ['url'=>'','params'=>[]]
     */
    public function getNextPaymentForm($url, $postParams=null)
    {
        if($postParams){
            $htmlTxt = self::post($url,$postParams);
        }
        $htmlTxt = self::httpGet($url);

        $crawler = new Crawler($htmlTxt);
        $jumpUrl = '';
        foreach ($crawler->filter('form') as $n){
            $jumpUrl = $n->getAttribute('action');
        }
        $jumpParams = [];
        foreach ($crawler->filter('form > input') as $input) {
            $field = $input->getAttribute('name');
            if(!$field) continue;
            $jumpParams[$field] = $input->getAttribute('value');

        }

        return ['url'=>$jumpUrl,'params'=>$jumpParams];
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
     * RSA公钥加密
     *
     * @param string $plainText 代加密明文字符串
     * @param string $pubKey 公钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * @throws OperationFailureException
     * @return string
     */
    public static function rsaPublicEncrypt(string $plainText, string $pubKey)
    {
        if(substr($pubKey,0,26)!='-----BEGIN PUBLIC KEY-----'){
            $wrapStr = wordwrap($pubKey, 64, "\n", true);
            $pubKey = "-----BEGIN PUBLIC KEY-----\n"
                .$wrapStr
                .= "\n-----END PUBLIC KEY-----";
        }

        $pubKeyRes = openssl_get_publickey($pubKey);
        if(!$pubKeyRes) {
            throw new OperationFailureException('RSA公钥不合法');
        }
        openssl_public_encrypt($plainText, $cryptBuffer, $pubKeyRes);
        openssl_free_key($pubKeyRes);

        return base64_encode($cryptBuffer);
    }

    /**
     * RSA公钥加密
     *
     * @param string $cryptText 待解密密文
     * @param string $pubKey 公钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * @throws OperationFailureException
     * @return string
     */
    public static function rsaPublicDecrypt(string $cryptText, string $pubKey)
    {
        if(substr($pubKey,0,26)!='-----BEGIN PUBLIC KEY-----'){
            $wrapStr = wordwrap($pubKey, 64, "\n", true);
            $pubKey = "-----BEGIN PUBLIC KEY-----\n"
                .$wrapStr
                .= "\n-----END PUBLIC KEY-----";
        }

        $pubKeyRes = openssl_get_publickey($pubKey);
        if(!$pubKeyRes) {
            throw new OperationFailureException('RSA公钥不合法');
        }
        openssl_public_decrypt(base64_decode($cryptText), $plainText, $pubKeyRes);
        openssl_free_key($pubKeyRes);

        return base64_encode($plainText);
    }

    /**
     * @param string $cryptText 待解密密文
     * @param string $privKey 私钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * @return mixed
     */
    public static function rsaPrivateEncrypt(string $plainText, string $privKey)
    {
        if(substr($privKey,0,31)!='-----BEGIN RSA PRIVATE KEY-----'){
            $privKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap($privKey, 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";
        }

        $privateKeyRes = openssl_get_privatekey($privKey);
        // $privateKeyRes = openssl_get_privatekey($privKey, PASSPHRASE); // 如果使用密码
        if(!$privateKeyRes) {
            throw new Exception('RSA私钥不合法');
        }

        // 密文做 base64_decode()
        openssl_private_encrypt($plainText, $cryptBuffer, $privateKeyRes);
        openssl_free_key($privateKeyRes);

        return base64_encode($cryptBuffer);
    }

    /**
     * @param string $cryptText 待解密密文
     * @param string $privKey 私钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * @return mixed
     */
    public static function rsaPrivateDecrypt(string $cryptText, string $privKey)
    {
        if(substr($privKey,0,31)!='-----BEGIN RSA PRIVATE KEY-----'){
            $privKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap($privKey, 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";
        }

        $privateKeyRes = openssl_get_privatekey($privKey);
        // $privateKeyRes = openssl_get_privatekey($privKey, PASSPHRASE); // 如果使用密码
        if(!$privateKeyRes) {
            throw new Exception('RSA私钥不合法');
        }

        // 密文做 base64_decode()
        openssl_private_decrypt(base64_decode($cryptText), $decrypted, $privateKeyRes);
        openssl_free_key($privateKeyRes);

        return $decrypted;
    }

    /**
     * RSA验签
     *
     * string $data 待签名数据
     * string $sign 需要验签的签名
     * string string $pubKey 公钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * return bool 验签是否通过
     */
    function rsaVerify($data, $sign, $pubKey)
    {
        if(substr($pubKey,0,26)!='-----BEGIN PUBLIC KEY-----'){
            $wrapStr = wordwrap($pubKey, 64, "\n", true);
            $pubKey = "-----BEGIN PUBLIC KEY-----\n"
                .$wrapStr
                .= "\n-----END PUBLIC KEY-----";
        }

        $res = openssl_get_publickey($pubKey);
        // 调用openssl内置方法验签，返回bool值
        $result = (boolean)openssl_verify($data, base64_decode($sign), $res);
        // 释放资源
        openssl_free_key($res);

        // 返回资源是否成功
        return $result;
    }
}