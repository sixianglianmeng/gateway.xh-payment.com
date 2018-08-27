<?php

namespace app\lib\payment\channels;

use app\common\exceptions\OperationFailureException;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\common\models\model\User;
use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use Yii;

class BasePayment
{
    //充值响应方式:页面直接往上游跳转
    const RENDER_TYPE_REDIRECT = 'redirect';
    //充值响应方式:显示二维码/条码
    const RENDER_TYPE_QR = 'qr';
    //充值响应方式:唤起客户端
    const RENDER_TYPE_NATIVE = 'native';

    //订单信息
    protected $order = null;
    //提款代付信息
    protected $remit = null;
    //商户信息
    protected $merchant = null;
    //商户支付配置信息
    protected $paymentInfo = null;
    //三方渠道账户等配置信息
    protected $channelAccount = null;
    //三方渠道配置网关地址等信息
    protected $paymentConfig = null;
    //三方渠道处理类
    protected $paymentHandle = null;

    /**********各接口操作结果数据结构,每种三方渠道必须返回统一的结构,上层才能处理***********/
    //收银台接口响应结果
    const RECHARGE_CASHIER_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            'type'             => self::RENDER_TYPE_REDIRECT,
            'url'              => '',//get跳转链接
            'channel_order_no' => '',//三方支付流水号
            'formHtml'         => '',//自动提交的form表单HTML
            'qr'               => '',//自动提交的form表单HTML
            'scheme'           => '',//唤醒客户端的scheme
        ],
    ];

    //网银支付跳转接口结果
    const RECHARGE_WEBBANK_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            'url'      => '',//get跳转链接
            'formHtml' => '',//自动提交的form表单HTML
        ],
    ];
    //支付通知解析结果
    const RECHARGE_NOTIFY_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            //订单对象 app\common\models\model\Order
            'order'            => null,
            //平台订单号
            'order_no'         => '',
            //订单实际支付金额
            'amount'           => 0,
            'trade_status'     => Order::STATUS_NOTPAY,//业务状态，需转换为Order表状态,
            //充值结果描述
            'msg'              => 'fail',
            //三方订单流水号
            'channel_order_no' => '',
            //三方成功时间
            'success_time'     => '',
        ],
    ];

    //收款订单查询接口结果商户此支付方式通道开关未打
    const RECHARGE_QUERY_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            'channel_order_no' => '',//三方订单号',
            'amount'           => '',//实际订单金额,可选,
            'trade_status'     => "",//业务状态，需转换为Order表状态,
        ],
    ];
    //出款接口结果
    const REMIT_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            'channel_order_no' => '',//三方订单号',
            'amount'           => '',//实际出款金额,可选,
            'bank_status'      => '',//三方银行状态,需转换为Remit表状态',
        ],
    ];
    //出款订单查询接口结果
    const REMIT_QUERY_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            //订单对象 app\common\models\model\Remit
            'remit'            => null,
            //订单号
            'order_no'         => '',
            //订单实际出款金额
            'amount'           => 0,
            'channel_order_no' => '',//三方订单号'
            //出款状态 Remit::STATUS_BANK_PROCESSING|Remit::BANK_STATUS_SUCCESS|Remit::BANK_STATUS_FAIL
            'bank_status'      => '',//三方银行状态,需转换为Remit表状态',
        ],
    ];
    //余额查询接口结果
    const BALANCE_QUERY_RESULT = [
        'status'  => Macro::FAIL,
        'message' => '',
        'data'    => [
            'balance'        => '',//三方账户可用余额',
            'frozen_balance' => '',//三方账户冻结余额',
        ],
    ];

    public function __construct(...$arguments)
    {
    }


    public function setOrder(Order $order)
    {
        $this->order = $order;
    }

    public function setRemit(Remit $remit)
    {
        $this->remit = $remit;
    }

    public function setMerchant(User $merchant)
    {
        $this->merchant = $merchant;
    }

    public function setPaymentInfo(UserPaymentInfo $paymentInfo)
    {
        $this->paymentInfo = $paymentInfo;
    }

    public function setChannelAccount(ChannelAccount $channelAccount)
    {
        $this->channelAccount = $channelAccount;
    }

    /**
     * 根据订单和渠道账户配置设置支付配置
     *
     */
    public function setPaymentConfig($channelAccount)
    {
        $this->setChannelAccount($channelAccount);

        $channel    = $channelAccount->channel;
        $baseConfig = $envConfig = [];

        $paymentConfig = $channelAccount->getAppSectets();
        if (empty($paymentConfig) || !is_array($paymentConfig) || empty($channelAccount->merchant_id)) {
            throw new OperationFailureException("收款渠道KEY配置错误:channelAccountId:{$channelAccount->id}", Macro::ERR_PAYMENT_CHANNEL_CONFIG);
        }

        $channelAccountConfigs = $channelAccount->toArray();
        unset($channelAccountConfigs['app_secrets']);
        $paymentConfig = \yii\helpers\ArrayHelper::merge($paymentConfig, $channelAccountConfigs);

        $paymentConfig['merchantId'] = $channelAccount->merchant_id;
        $paymentConfig['appId']      = $channelAccount->app_id;

        //充值回调域名
        $notifyBase = SiteConfig::cacheGetContent('payment_notify_base_uri');
        if (!$notifyBase && isset(Yii::$app->request->hostInfo)) $notifyBase = Yii::$app->request->hostInfo;
        $paymentConfig['paymentNotifyBaseUri'] = $notifyBase;

        $this->paymentConfig = $paymentConfig;
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
    public static function md5Sign(array $params, string $signKey)
    {
        unset($params['key']);

        $signParams = [];
        foreach ($params as $key => $value) {
            if ($value == '') continue;
            $signParams[] = "$key=$value";
        }

        sort($signParams, SORT_STRING);
        $strToSign = implode('&', $signParams);

        $signStr = md5($strToSign . '&key=' . $signKey);

        return $signStr;
    }

    /**
     *
     * curl发送post请求
     *
     * @param string $url 请求地址
     * @param array $postData 请求数据
     *
     * @return bool|string
     */
    public static function curlPost(string $url, array $postData, $header = [], $timeout = 10)
    {
        $headers = [];
        try {
            $ch        = curl_init();
            $headers[] = "Accept-Charset: utf-8";
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $body = curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            Yii::error('request to channel: ' . $url . ' ' . json_encode($postData, JSON_UNESCAPED_UNICODE) . ' ' . $body . ' ' . curl_error($ch));
            $body = $e->getMessage();
        }

        Yii::info('request to channel: ' . $url . ' ' . json_encode($postData, JSON_UNESCAPED_UNICODE) . ' ' . $body);

        return $body;
    }

    /**
     *
     * 发送http get 请求
     *
     * @param sttring $url 请求地址
     * @param array $headers http header
     *
     * @return bool|string
     */
    public static function httpGet($url, $headers = [], $timeout = 20)
    {
        try {
            $client   = new \GuzzleHttp\Client();
            $response = $client->get($url,['timeout' => $timeout]);
            $httpCode = $response->getStatusCode();
            $body     = (string)$response->getBody();
        } catch (\Exception $e) {
            if ($e->hasResponse()) {
                $httpCode = $e->getResponse()->getStatusCode();
                $body = (string)$e->getResponse()->getBody();
            } else {
                $body = $e->getMessage();
                $httpCode = 403;
            }
        }

        Yii::info('request to channel: ' . $url . ' ' . $httpCode . ' ' . $body);

        return $body;
    }

    /**
     *
     * 发送post请求
     *
     * @param string $url 请求地址
     * @param array $postData 请求数据
     *
     * @return bool|string
     */
    public static function post(string $url, array $postData, $header = [], $timeout = 20)
    {
        $headers = [];
        try {
            $client   = new \GuzzleHttp\Client();
            $response = $client->request('POST', $url, [
                'headers'     => $headers,
                'timeout'     => $timeout,
                'body'        => http_build_query($postData),
                'form_params' => ($postData),
            ]);

            //            $response = $client->get($url.'?'.http_build_query($postData));
            $httpCode = $response->getStatusCode();
            $body     = (string)$response->getBody();
        } catch (\Exception $e) {
            if ($e->hasResponse()) {
                $httpCode = $e->getResponse()->getStatusCode();
                $body = (string)$e->getResponse()->getBody();
            } else {
                $body = $e->getMessage();
                $httpCode = 403;
            }
        }
        Yii::$app->params['apiRequestLog']['http_code'] = $httpCode;

        Yii::info('request to channel: ' . $url . ' ' . json_encode($postData, JSON_UNESCAPED_UNICODE) . ' ' . $body);

        return $body;
    }

    /**
     * 构造提交表单HTML数据
     * @param array $params 请求参数数组
     * @param string $url 网关地址
     * @param bool $autoSubmit 是否自动提交表单
     * @param $method 提交方式。两个值可选：post、get
     * @param $buttonName 确认按钮显示文字
     * @return 提交表单HTML文本
     */
    public static function buildForm(array $params, string $url, bool $autoSubmit = true, $method = 'post', $buttonName = '确定')
    {

        $sHtml = "<form id='paymentForm' name='paymentForm' action='" . $url . "' method='" . $method . "'>\n";

        foreach ($params as $key => $value) {
            $sHtml .= "<input type='hidden' name='" . $key . "' value='" . $value . "'/>\n";
        }

        if ($autoSubmit) {
            $sHtml = $sHtml . "<input type='submit' value='" . $buttonName . "' style='display:none;'>\n</form>\n";
            $sHtml = $sHtml . "<script>document.forms['paymentForm'].submit();</script>\n";
        } else {
            $sHtml = $sHtml . "<input type='submit' value='" . $buttonName . "' />\n</form>\n";
        }

        return $sHtml;
    }


    /**
     * 获取收款订单异步通知地址
     * 需在配置文件中配置地址重写'/api/v1/callback/recharge-notify/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-notify',
     *
     * return string
     */
    public function getRechargeNotifyUrl()
    {
        return $this->paymentConfig['paymentNotifyBaseUri']."/api/v1/callback/recharge-notify/{$this->order->channel_id}";
    }

    /**
     * 获取收款订单同步步通知地址
     * 需在配置文件中配置地址重写'/api/v1/callback/recharge-return/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-return',
     *
     * return string
     */
    public function getRechargeReturnUrl()
    {
        return $this->paymentConfig['paymentNotifyBaseUri']."/api/v1/callback/recharge-return/{$this->order->channel_id}";
    }

    /**
     * 获取出款订单异步通知地址
     * 需在配置文件中配置地址重写'/api/v1/callback/remit-notify/<channelId:\d+>' => '/gateway/v1/web/callback/remit-notify',
     *
     * return string
     */
    public function getRemitNotifyUrl()
    {
        return $this->paymentConfig['paymentNotifyBaseUri']."/api/v1/callback/remit-notify/{$this->remit->channel_id}";
    }

    /**
     * 解析异步通知请求，返回订单
     *
     * return array BasePayment::RECHARGE_NOTIFY_RESULT
     */
    public function parseNotifyRequest(array $request)
    {
        //check sign

        //get order id and result from request

        throw new OperationFailureException("通道暂不支持解析异步通知", Macro::ERR_UNKNOWN);
    }

    /**
     * 解析同步通知请求，返回订单
     *
     * return array BasePayment::RECHARGE_NOTIFY_RESULT
     */
    public function parseReturnRequest(array $request)
    {
        //check sign

        //get order id and result from request

        throw new OperationFailureException("通道暂不支持解析同步通知:" . $this->getCallChildClassName(), Macro::ERR_UNKNOWN);
    }

    /**
     * 出款
     *
     * return array BasePayment::REMIT_RESULT
     */
    public function remit()
    {
        throw new OperationFailureException("通道暂不支持出款:" . $this->getCallChildClassName(), Macro::ERR_UNKNOWN);
    }

    /**
     * 出款状态查询
     *
     * @return array BasePayment::REMIT_QUERY_RESULT
     */
    public function remitStatus()
    {
        throw new OperationFailureException("通道暂不支持查询出款状态:" . $this->getCallChildClassName(), Macro::ERR_UNKNOWN);
    }

    /**
     * 余额查询
     *
     * return  array BasePayment::BALANCE_QUERY_RESULT
     */
    public function balance()
    {
        throw new OperationFailureException("通道暂不支持查询余额:" . $this->getCallChildClassName(), Macro::ERR_UNKNOWN);
    }

    /**
     * 网银支付
     */
    public function webBank()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 网银H5/WAP支付
     */
    public function bankH5()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 网银快捷支付
     */
    public function bankQuickPay()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 银行转账
     */
    public function bankTransfer()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信扫码支付
     */
    public function wechatQr()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信快捷扫码支付
     */
    public function wechatCodeBar()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信H5支付
     */
    public function wechatH5()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);

    }

    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 支付宝H5支付
     */
    public function alipayH5()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 支付宝条码支付
     */
    public function alipayCodeBar()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * QQ扫码支付
     */
    public function qqQr()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * QQ H5支付
     */
    public function qqH5()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * QQ条码支付
     */
    public function qqCodeBar()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 京东钱包支付
     */
    public function jdWallet()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 银联扫码支付
     */
    public function unoinPayQr()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 银联H5支付
     */
    public function unionPayH5()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 银联快捷支付
     */
    public function unoinPayQuick()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 京东H5支付
     */
    public function jdH5()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 京东扫码支付
     */
    public function jdQr()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 支付宝转账
     */
    public function alipayTransfer()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信转账
     */
    public function wechatTransfer()
    {
        throw new OperationFailureException("通道程序暂不支持此支付方式:" . $this->getCallChildClassName() . ':' . __FUNCTION__, Macro::ERR_UNKNOWN);
    }

    protected function getCallChildClassName()
    {
        $class  = (new \ReflectionClass(__CLASS__))->getShortName();
        $clsArr = explode('\\', $class);
        $class  = array_pop($clsArr);
        return $class;
    }

    /**
     * aes加密
     *
     * @param string $input 要加密的数据
     * @params string 加密key
     * @return string 加密后的数据
     */
    public static function aesEncrypt($input, $key)
    {
        $data = openssl_encrypt($input, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
        $data = base64_encode($data);
        return $data;
    }

    /**
     * aes解密
     *
     * @param string $sStr 要解密的数据
     * @param string $sKey 加密key
     * @return string 解密后的数据
     */
    public static function aesDecrypt($sStr, $sKey)
    {
        $decrypted = openssl_decrypt(base64_decode($sStr), 'AES-128-ECB', $sKey, OPENSSL_RAW_DATA);
        return $decrypted;
    }

}