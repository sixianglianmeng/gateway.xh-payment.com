<?php

namespace app\lib\payment\channels\yf;

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
 * 易宝接口
 *
 * @package app\lib\payment\channels\yzb
 */
class YfBasePayment extends BasePayment
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

        $data['p1_MerId']      = ControllerParameterValidator::getRequestParam($request, 'p1_MerId', null, Macro::CONST_PARAM_TYPE_STRING, 'p1_MerId错误！', [5]);
        $data['r0_Cmd']     = ControllerParameterValidator::getRequestParam($request, 'r0_Cmd', null, Macro::CONST_PARAM_TYPE_STRING, 'r0_Cmd错误！', [1]);
        $data['r1_Code']     = ControllerParameterValidator::getRequestParam($request, 'r1_Code', null, Macro::CONST_PARAM_TYPE_STRING, 'r1_Code错误！', [1]);
        $data['r2_TrxId']     = ControllerParameterValidator::getRequestParam($request, 'r2_TrxId', null, Macro::CONST_PARAM_TYPE_STRING, 'r2_TrxId错误！', [5]);
        $data['r3_Amt']  = ControllerParameterValidator::getRequestParam($request, 'r3_Amt', null, Macro::CONST_PARAM_TYPE_DECIMAL, 'r3_Amt错误！');
        $data['r4_Cur']   = ControllerParameterValidator::getRequestParam($request, 'r4_Cur', null, Macro::CONST_PARAM_TYPE_STRING, 'r4_Cur错误！', [2]);
        $data['r5_Pid']   = ControllerParameterValidator::getRequestParam($request, 'r5_Pid', '', Macro::CONST_PARAM_TYPE_STRING, 'r5_Pid错误！');
        $data['r6_Order'] = ControllerParameterValidator::getRequestParam($request, 'r6_Order', '', Macro::CONST_PARAM_TYPE_ORDER_NO, 'r6_Order错误！', [3]);
        $data['r7_Uid']       = ControllerParameterValidator::getRequestParam($request, 'r7_Uid', '', Macro::CONST_PARAM_TYPE_STRING, 'r7_Uid错误！');
        $data['r8_MP']       = ControllerParameterValidator::getRequestParam($request, 'r8_MP', '', Macro::CONST_PARAM_TYPE_STRING, 'r8_MP错误！');
        $data['r9_BType']       = ControllerParameterValidator::getRequestParam($request, 'r9_BType', '', Macro::CONST_PARAM_TYPE_STRING, 'r9_BType错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'hmac', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);
        $signHmacSafe                = ControllerParameterValidator::getRequestParam($request, 'hmac_safe', null, Macro::CONST_PARAM_TYPE_STRING, 'hmac_safe错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['r6_Order']);
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

        $localSign = self::hmacMd5(implode($data),trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(
            !empty($request['r1_Code']) && $request['r1_Code'] == '1'
            && !empty($request['r0_Cmd']) && $request['r0_Cmd'] == 'Buy'
            && $data['r3_Amt']>0
        ) {
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['r3_Amt'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['channel_order_no'] = $data['r2_TrxId'];
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

        $params = [
            'version'                                       => '1.0.0',
            'merchantId'                                    => $this->order['channel_merchant_id'],
            'merchantOrderId'                               => $this->order['order_no'],
            'merchantOrderTime'                             => date('YmdHis'),
            'merchantOrderAmt'                              => bcmul(100,$this->order['amount']),
            'merchantDisctAmt'                              => '',
            'merchantOrderCurrency'                         => '156',
            'gwType'                                        => 'web',
            'frontUrl'                                      => str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/ltb/return",
            'backUrl'                                       => str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/ltb/notify",
            'bankId'                                        => $bankCode,
            'userType'                                      => '01',
            'merchantUserId'                                => $this->order['merchant_code'],
            'userName'                                      => '',
            'mobileNum'                                     => '',
            'userIp'                                        => '',
            'merchantOrderDesc'                             => '账户充值',
            'merchantSettleInfo'                            => json_encode([
                'merchantId'=>$this->order['channel_merchant_id'],
                'merchantName'=>$this->order['channel_name'],
                'orderAmt'=>bcmul(100,$this->order['amount']),
                'sumGoodsName'=>'',
                ]),
            'transTimeout'                                  => '300',
            'rcExt'                                         => '',
            'msgExt'                                        => '',
            'misc'                                          => 'Buy',
        ];
        $key = $this->getRandomString(16);
        $params = json_encode($params);
        //对订单内容进行AES 加密
        $data = base64_encode($this->aes_encrypt($params,$key));
        //对 $key 进行RSA公钥 非对称加密
        openssl_public_encrypt($key,$ek,$this->paymentConfig['public_key']);
        $bek = base64_encode($ek);
        //对AES加密的 $key 进行RSA 私钥签名
        $_key = $this->paymentConfig['private_key'];
        $privateKey = openssl_get_privatekey($_key);
        openssl_sign($ek,$sign,$privateKey);
        openssl_free_key($privateKey);
        $sign = base64_encode($sign);
        //http://mertest.yfpayment.com/payment/payset.do
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/payment/payset.do';
        $subData['merchantId'] =  $this->order['channel_merchant_id'];
        $subData['data'] =  $data;
        $subData['enc'] =  $bek;
        $subData['sign'] =  $sign;
        $resTxt = self::post($subData,$requestUrl);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$this->order->merchant_id,
            'merchant_name'=>$this->order->merchant_account,
            'channel_account_id'=>$this->order->channelAccount->id,
            'channel_name'=>$this->order->channelAccount->channel_name,
        ];
        LogicApiRequestLog::outLog($requestUrl, 'POST', '', 200,0, $params,$subData);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if(isset($res['respCode']) && $res['respCode']=='0000' && !empty($res['token'])){
                //http://mertest.yfpayment.com/payment/payshow.do
                $fromData['version'] = '1.0.0';
                $fromData['merchantId'] = $this->order['channel_merchant_id'];
                $fromData['token'] = $res['token'];
                $fromUrl = $this->paymentConfig['gateway_base_uri'].'payment/payshow.do';
                $formTxt = self::buildForm($fromData,$fromUrl);
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                $ret['data']['formHtml'] = $formTxt;
            }else{
                $ret['msg'] = $res['respCode'];
            }
        }
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
        $params = ['p0_Cmd' => 'QueryOrdDetail',
                   'p1_MerId' => $this->order['channel_merchant_id'],
                   'p2_Order' => $this->order['order_no'],
                   'pv_Ver' => '3.0',
                   'p3_ServiceType' => '2',
        ];

        $params['hmac'] = self::hmacMd5(implode($params),trim($this->paymentConfig['key']));
        $params['hmac_safe'] = self::getHamcSafe($params,trim($this->paymentConfig['key']));

        $requestUrl = 'https://cha.yeepay.com/app-merchant-proxy/command';
        $resTxt = self::post($requestUrl, $params);
        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                !empty($res['r1_Code']) && $res['r1_Code'] == '1'
                && !empty($res['r0_Cmd']) && $res['r0_Cmd'] == 'QueryOrdDetail'
            ) {
                if($res['rb_PayStatus'] == 'SUCCESS' && $res['r3_Amt']>0){
                    $ret['data']['trade_status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['r3_Amt'];
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??'订单查询失败:'.$resTxt;
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
         return true;
    }

    /**
     * AES/ECB/PKCS5Padding
     * 加密
     * @param String input 加密的字符串
     * @param String key   解密的key
     */
    public function aes_encrypt($input,$key) {

        $size = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_ECB);
        $input = $this->pkcs5_pad($input, $size);
        $td = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_ECB, '');
        $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
        mcrypt_generic_init($td, $key, $iv);
        $data = mcrypt_generic($td, $input);
        mcrypt_generic_deinit($td);
        mcrypt_module_close($td);
        $data = bin2hex($data);
        return $data;

    }
    /**
     * 填充方式 pkcs5
     * @param String text 		 原始字符串
     * @param String blocksize   加密长度
     * @return String
     */
    private function pkcs5_pad($text, $blocksize) {

        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);

    }

    /**
     * 随机生成 AES 加密密码
     * @param $len
     * @param null $chars
     * @return string
     */
    public function getRandomString($len, $chars=null)
    {
        if (is_null($chars)){
            $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        }
        mt_srand(10000000*(double)microtime());
        for ($i = 0, $str = '', $lc = strlen($chars)-1; $i < $len; $i++){
            $str .= $chars[mt_rand(0, $lc)];
        }
        return $str;
    }

}