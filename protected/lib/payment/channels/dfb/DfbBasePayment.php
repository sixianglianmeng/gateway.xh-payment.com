<?php

namespace app\lib\payment\channels\dfb;

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
 * 代付宝支付接口
 *
 * @package app\lib\payment\channels\dfb
 */
class DfbBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_WEBBANK => 'OnlinePay',
        Channel::METHOD_WECHAT_QR => 'WEIXIN',
        Channel::METHOD_WECHAT_H5 => 'WEIXINWAP',
        Channel::METHOD_ALIPAY_QR => 'alipay',
        Channel::METHOD_ALIPAY_H5 => 'ALIPAYWAP',
        Channel::METHOD_QQ_QR => 'QQ',
        Channel::METHOD_QQ_H5 => 'QQWAP',
        Channel::METHOD_UNIONPAY_QR => 'UnionPay',
        Channel::METHOD_JD_QR => 'JDPAY',
        Channel::METHOD_BANK_QUICK => 'Nocard_H5',
    ];

    CONST BANK_NAMES = [
        'BJRCB'=>'北京农商银行',
        'BOC'=>'中国银行',
        'CEB'=>'中国光大银行',
        'CIB'=>'兴业银行',
        'CITIC'=>'中信银行',
        'CMBC'=>'中国民生银行',
        'ICBC'=>'中国工商银行',
        'SPABANK'=>'平安银行',
        'SPDB'=>'浦发银行',
        'PSBC'=>'中国邮政储蓄银行',
        'NJCB'=>'南京银行',
        'COMM'=>'交通银行',
        'CMB'=>'招商银行',
        'CCB'=>'中国建设银行',
        'GDB'=>'广发银行',
        'HKBEA'=>'东亚银行',
    ];

    const DF_PUBLIC_KEY = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCr8YNPeMh4M/5HCGg8Re121Wuors4kYWaD3glb2GNd8+/0cb3q6Xh9Zl1VMgB/L9xzBRvoRhfCWzcNkcrsUbriIJheQnXD5vl05cTnfzhh7XL3LlqMj0ZHWmRnhLxgk26m0IHnreZQfhW0uZyGl6A8rxcDbkIuknvUbTHlpQlbEwIDAQAB';

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
        $callbackParamsName = ["p1_MerId","r0_Cmd","r1_Code","r2_TrxId","r3_Amt","r4_Cur","r5_Pid","r6_Order",
            "r8_MP","r9_BType","ro_BankOrderId","rp_PayDate"
        ];

        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['r6_Order'] = ControllerParameterValidator::getRequestParam($request, 'r6_Order', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['r3_Amt'] = ControllerParameterValidator::getRequestParam($request, 'r3_Amt', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['r1_Code'] = ControllerParameterValidator::getRequestParam($request, 'r1_Code', null, Macro::CONST_PARAM_TYPE_INT, '状态错误！');
        $sign = ControllerParameterValidator::getRequestParam($request, 'hmac', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

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

        $localSign =  self::hmacMd5Sign($data, trim($this->paymentConfig['key']));//self::rsaVerify($data, $sign, self::DF_PUBLIC_KEY);
        if($localSign != $sign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;

        if ($data['r1_Code']==1) {
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['amount'] = $data['r3_Amt'];
            $ret['data']['channel_order_no'] = $data['r2_TrxId'];
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
        $payTypeCode = $bankCode = '';
        //网银支付获取银行代码
        if($this->order['pay_method_code']==Channel::METHOD_WEBBANK){
            $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

            if(empty($bankCode)){
                throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
            }
        }

        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        //其他支付获取支付通道代码
        else{
            $payTypeCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'p0_Cmd' => 'Buy',
            'p1_MerId' => $this->order['channel_merchant_id'],
            'p2_Order' => $this->order['order_no'],
            'p3_Cur' => 'CNY',
            'p4_Amt' => bcadd(0, $this->order['amount'], 2),
            'p5_Pid' => 'account_recharge',
// 'p6_Pcat' => '', 'p7_Pdesc' => '',
            'p8_Url' => str_replace('http','http',$this->getRechargeNotifyUrl()),
//            'p9_MP' => '',
            'pa_FrpId' => $payTypeCode,
            'pg_BankCode' => $bankCode,
//            'ph_Ip' => '',
//            'pi_Url' => '',
        ];

        $params['hmac'] = self::hmacMd5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/controller.action";

        $ret = self::RECHARGE_CASHIER_RESULT;
        if(in_array($this->order['pay_method_code'],[Channel::METHOD_WEBBANK,Channel::METHOD_BANK_QUICK,Channel::METHOD_QQ_H5])){
            $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
            $ret['data']['formHtml'] = self::buildForm($params,$requestUrl);
            $ret['status'] = Macro::SUCCESS;

            //接口日志记录
            LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, '', $params);

            return $ret;
        }

        $resTxt = self::post($requestUrl,$params,[],20);
        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);


        if (!empty($resTxt)) {
//            var_dump($resTxt);

            $res = json_decode($resTxt,true);

            if(is_array($res) && !empty($res['r1_Code']) && !empty($res['r3_PayInfo']) && $res['r1_Code']==1) {
                $localSign = self::hmacMd5Sign($res, trim($this->paymentConfig['key']));
                Yii::info('order query ret sign: ' . $this->order['order_no'] . ' local:' . $localSign . ' back:' . $res['hmac']);
                if($localSign == $res['hmac']){
                    $ret['status'] = Macro::SUCCESS;
                    $ret['data']['channel_order_no'] = $res['r2_TrxId'];

                    if(Util::isMobileDevice() && substr($res['r3_PayInfo'],0,4)=='http'){
                        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                        $ret['data']['url'] = $res['r3_PayInfo'];
                    }else{
                        $ret['data']['type'] = self::RENDER_TYPE_QR;
                        $ret['data']['qr'] = $res['r3_PayInfo'];
                    }
                }

            }
        }



        return $ret;
    }

    public function wechatH5()
    {
        return $this->wechatQr();
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

    public function qqH5()
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
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){

        $params = [
            'p0_Cmd'=>'QueryOrdDetail',
            'p1_MerId'=>$this->order['channel_merchant_id'],
            'p2_Order'=>$this->order['order_no'],
        ];
        $params['sign'] =self::hmacMd5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/controller.action";
        $resTxt = self::post($requestUrl,$params);

        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt,true);

            if(is_array($res) && !empty($res['hmac'])) {
                $localSign = self::hmacMd5Sign($res, trim($this->paymentConfig['key']));
                Yii::info('order query ret sign: ' . $this->order['order_no'] . ' local:' . $localSign . ' back:' . $res['sign']);
                if (
                    isset($res['r1_Code']) && $res['r1_Code'] == '1'
                    && isset($res['rb_PayStatus'])
                ) {
                    if (isset($res['rb_PayStatus']) == 'SUCCESS'
                        && !empty($res['r3_Amt'])) {
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['amount'] = $res['r3_Amt'];
                        $ret['data']['channel_order_no'] = $res['r2_TrxId'];
                        $ret['data']['trade_status'] = Order::STATUS_PAID;
                    }
                } else {
                    $ret['message'] = '订单查询失败:' . $resTxt;
                }
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
        $params = [
            'p0_Cmd'=>'Money',
            'p1_MerId'=>$this->paymentConfig['merchantId'],
            'p2_Cur'=>'CNY',
        ];
        $params['sign'] = self::rsaSign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/GateWay/ReceiveMoneyRange.aspx";
        $resTxt = self::post($requestUrl,$params);

        Yii::info('balance query result: '.$this->order['channel_merchant_id'].' '.$resTxt);
        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = self::xmlToArray($resTxt);

            if(is_array($res) && !empty($res['sign'])) {
                $res['sign'] = str_replace("*", "+", $res['sign']);
                $res['sign'] = str_replace("-", "/", $res['sign']);
                $localSign = self::rsaVerify($res, $res['sign'], trim(self::DF_PUBLIC_KEY));
                Yii::info('order query ret sign: ' . $this->remit['order_no'] . ' local:' . $localSign . ' back:' . $res['sign']);
                if (isset($res['r1_Money'])
                ) {
                    $ret['status']         = Macro::SUCCESS;
                    $ret['data']['balance'] = $res['r1_Money'];
                } else {
                    $ret['message'] = '订单查询失败:' . $resTxt;
                }
            }
        }

        return  $ret;
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
            throw new OperationFailureException("通道讯通宝银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'p0_Cmd' => 'TransPay',
            'p1_MerId' => $this->remit['channel_merchant_id'],
            'p2_Order' => $this->remit['order_no'],
            'p3_CardNo' => $this->remit['bank_no'],
            'p4_BankName' => $bankCode,
            'p5_AtName' => $this->remit['bank_account'],//mb_convert_encoding($this->remit['bank_account'],'GBK'),
            'p6_Amt' => bcadd(0, $this->remit['amount'], 2),
//            'pb_CusUserId' => '',
            'pc_NewType' => 'PRIVATE',
//            'pd_BranchBankName' => '',
//            'pe_Province' => '',
//            'pf_City' => '',
//            'pg_Url' => str_replace('http','http',$this->getRemitNotifyUrl()),
        ];

        $params['hmac'] = self::rsaSign($params, trim($this->paymentConfig['rsa_private']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/controller.action';
        $resTxt = self::post($requestUrl, $params);

        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit to bank raw result: '.$this->remit['order_no'].' '.$resTxt);
        if (!empty($resTxt)) {
            parse_str(str_replace("\n",'&',$resTxt),$res);

            if(is_array($res) && !empty($res['hmac'])){
                $localSign = self::rsaVerify($res,$res['hmac'],trim(self::DF_PUBLIC_KEY));
                Yii::info('remit commit ret sign: '.$this->remit['order_no'].' local:'.$localSign.' back:'.$res['hmac']);
                if (
                    isset($res['r1_Code']) && $res['r1_Code'] == '0000'
                ) {
                    $ret['data']['channel_order_no'] = $res['r2_TrxId'];
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;

                    $ret['status'] = Macro::SUCCESS;
                } else {
                    $ret['message'] = $res['r7_Desc'];
                }
            }else{
                $ret['message'] = "{$resTxt}";
            }
        }else{
            $ret['message'] = "{$resTxt}";
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
            'p0_Cmd'=>'TransQuery',
            'p1_MerId'=>$this->remit['channel_merchant_id'],
            'p2_Order'=>$this->remit['order_no'],
        ];
        $params['hmac'] = self::rsaSign($params, trim($this->paymentConfig['rsa_private']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/controller.action';
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit['order_no'];

        if (!empty($resTxt)) {
            $res = json_decode($resTxt,true);
            if(is_array($res) && !empty($res['hmac'])){
                $localSign = self::rsaVerify($res,$res['hmac'],trim(self::DF_PUBLIC_KEY));
                Yii::info('remit query ret sign: '.$this->remit['order_no'].' local:'.$localSign.' back:'.$res['hmac']);
                if (
                    isset($res['r1_Code']) && $res['r1_Code'] == '0000'
                ) {
                    $ret['data']['channel_order_no'] = $res['r2_TrxId'];
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;

                    $ret['status'] = Macro::SUCCESS;
                }
                elseif(isset($res['r1_Code']) && $res['r1_Code'] == '3002') {
                    $ret['data']['channel_order_no'] = $res['r2_TrxId'];
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = $res['r7_Desc']??$resTxt;
                    $ret['status'] = Macro::SUCCESS;
                }
                else {
                    $ret['message'] = $res['r7_Desc'];
                }
            }else{
                $ret['message'] = "{$resTxt}";
            }
        }else{
            $ret['message'] = "{$resTxt}";
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
     *
     * 发送post请求
     *
     * @param string $url 请求地址
     * @param array|string $postData 请求数据
     *
     * @return bool|string
     */
    public static function post(string $url, $postData, $header = [], $timeout = 10)
    {
        $headers = [];
        try {
            $ch = curl_init(); //初始化curl
            curl_setopt($ch,CURLOPT_URL, $url);//抓取指定网页
            curl_setopt($ch, CURLOPT_HEADER, 0);//设置header
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
            curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, ($postData));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            $body = curl_exec($ch);//运行curl
            curl_close($ch);
        } catch (\Exception $e) {
            $body     = $e->getMessage();
        }

        Yii::info('request to channel: ' . $url . ' data:' . http_build_query($postData). ' response:' . $body);

        return $body;
    }

    /**
     *
     * 获取参数hamcMd5Sign签名
     *
     * @param array $params 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */

    //生成本地的安全签名数据
    public static function hmacMd5Sign(array $data,$secretKey)
    {
        unset($data['hmac']);
        $strToSign  = implode('',$data);

        $sign = self::hmacMd5($strToSign,$secretKey);
        Yii::info('hamcMd5Sign string: '.$strToSign.' '.$sign);
        return $sign;

    }


    //生成hmac
    public static function hmacMd5(string $data,$key)
    {
        // RFC 2104 HMAC implementation for php.
        // Creates an md5 HMAC.
        // Eliminates the need to install mhash to compute a HMAC
        // Hacked by Lance Rushing(NOTE: Hacked means written)

        //需要配置环境支持iconv，否则中文参数不能正常处理
        $key = iconv("GBK","UTF-8",$key);
        $data = iconv("GBK","UTF-8",$data);
        $b = 64; // byte length for md5
        if (strlen($key) > $b) {
            $key = pack("H*",md5($key));
        }
        $key = str_pad($key, $b, chr(0x00));
        $ipad = str_pad('', $b, chr(0x36));
        $opad = str_pad('', $b, chr(0x5c));
        $k_ipad = $key ^ $ipad ;
        $k_opad = $key ^ $opad;


        return md5($k_opad . pack("H*",md5($k_ipad . $data)));
    }

    /**
     *
     * 获取参数rsa签名
     *
     * @param array $params 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */
    public static function rsaSign(array $params, string $signKey){
        $strToSign = implode('', $params);
//        $signStr = self::rsaPrivateEncrypt($strToSign, $signKey);

        if(substr($signKey,0,31)!='-----BEGIN RSA PRIVATE KEY-----'){
            $signKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap($signKey, 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";
        }

        $res = openssl_get_privatekey($signKey);
        //调用openssl内置签名方法，生成签名$sign
        openssl_sign($strToSign, $sign, $res);
        openssl_free_key($res);
        $signStr = base64_encode($sign);

        Yii::info('rsaSign string: '.$signStr.' raw: '.$strToSign);
        return $signStr;
    }

    /**
     * RSA验签
     *
     * array $data 待签名数据
     * string $sign 需要验签的签名
     * string string $pubKey 公钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * return bool 验签是否通过
     */
    function rsaVerify($data, $sign, $pubKey)
    {
        unset($data['sign']);
        unset($data['rp_transTime']);
        if(substr($pubKey,0,26)!='-----BEGIN PUBLIC KEY-----'){
            $wrapStr = wordwrap($pubKey, 64, "\n", true);
            $pubKey = "-----BEGIN PUBLIC KEY-----\n"
                .$wrapStr
                    .= "\n-----END PUBLIC KEY-----";
        }
//var_dump($data);
        $strToSign = implode('', $data);
//        echo "回调参数: p1_MerId=2800&r0_Cmd=Buy&r1_Code=1&r2_TrxId=GM2018071815235554678318&r3_Amt=10.00&r4_Cur=RMB&r5_Pid=&r6_Order=118071815065664306&r7_Uid=&r8_MP=&r9_BType=2&rp_PayDate=2018/7/18%2015:24:58&sign=ITJMKhT4HMRmaesZMzF5yzUKwkz6Hz7u0Z6zX*MSF6Ec9EwFa*vvMABJcswi5Sh3AqoVB3aYJdNYimZOLQpjHuBo7yqemH-7JZ4epKaHl0r7ek78yhQ076mqFhsb9BGBGrBPYhugtuxqW6eRnzf3lg5l2RK*xWdUkX9DrfhWAZM=\n";
//        echo "\n\n验签字符串: \n";
//        echo ($strToSign);
//        echo "\n\n回传签名：\n";
//        echo ($sign);
//        echo "\n\npubkey: \n";
//        echo ($pubKey );

        $res = openssl_get_publickey($pubKey);
        // 调用openssl内置方法验签，返回bool值
        $result = (boolean)openssl_verify($strToSign, base64_decode($sign), $res);
        // 释放资源
        openssl_free_key($res);

        // 返回资源是否成功
        return $result;
    }

}