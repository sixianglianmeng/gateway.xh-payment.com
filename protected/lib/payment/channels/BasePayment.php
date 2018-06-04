<?php

namespace app\lib\payment\channels;

use app\common\models\model\Remit;
use Yii;
use app\common\models\model\ChannelAccount;
use app\common\models\model\User;
use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use app\common\models\model\Order;
use yii\base\Request;

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
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            'type'=>self::RENDER_TYPE_REDIRECT,
            'url'      => '',//get跳转链接
            'formHtml' => '',//自动提交的form表单HTML
            'qr' => '',//自动提交的form表单HTML
            'scheme' => '',//唤醒客户端的scheme
        ],
    ];

    //网银支付跳转接口结果
    const RECHARGE_WEBBANK_RESULT = [
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            'url'      => '',//get跳转链接
            'formHtml' => '',//自动提交的form表单HTML
        ],
    ];
    //支付通知解析结果
    const RECHARGE_NOTIFY_RESULT = [
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            //订单对象 app\common\models\model\Order
            'order' => null,
            //平台订单号
            'order_no' => '',
            //订单实际支付金额
            'amount' => 0,
            //订单状态 Macro::SUCCESS为成功，Macro::FAIL失败',其它正在支付
            'status' => Macro::FAIL,
            //充值结果描述
            'msg' => 'fail',
            //三方订单流水号
            'channel_order_no' => '',
            //三方成功时间
            'successTime' => '',
        ],
    ];
    //收款订单查询接口结果
    const RECHARGE_QUERY_RESULT = [
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            'channel_order_no' => '',//三方订单号',
            'trade_status'       => "",//Macro::SUCCESS|Macro::FAIL",
        ],
    ];
    //出款接口结果
    const REMIT_RESULT = [
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            'channel_order_no' => '',//三方订单号',
            'bank_status'       => '',//三方银行状态,需转换为Remit表状态',
        ],
    ];
    //出款订单查询接口结果
    const REMIT_QUERY_RESULT = [
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            'channel_order_no' => '',//三方订单号'
            'bank_status'       => '',//三方银行状态,需转换为Remit表状态',
        ],
    ];
    //余额查询接口结果
    const BALANCE_QUERY_RESULT = [
        'status' => Macro::FAIL,
        'message'=>'',
        'data' => [
            'balance'        => '',//三方账户可用余额',
            'frozen_balance' => '',//三方账户冻结余额',
        ],
    ];

    public function __construct(...$arguments)
    {
    }


    public function setOrder(Order $order){
        $this->order = $order;
    }

    public function setRemit(Remit $remit){
        $this->remit = $remit;
    }

    public function setMerchant(User $merchant){
        $this->merchant = $merchant;
    }

    public function setPaymentInfo(UserPaymentInfo $paymentInfo){
        $this->paymentInfo = $paymentInfo;
    }

    public function setChannelAccount(ChannelAccount $channelAccount){
        $this->channelAccount = $channelAccount;
    }

    /**
     * 根据订单和渠道账户配置设置支付配置
     *
     */
    public function setPaymentConfig($channelAccount)
    {
        $this->setChannelAccount($channelAccount);

        $channel = $channelAccount->channel;
        $baseConfig = $envConfig = [];

        //渠道代码(英文)，用于配置文件目录名等。配置文件真实地址为/config/payment/目录名/config.php,且payment目录可放置于不同环境目录下。
        $baseConfigFile = Yii::getAlias("@app/config/payment/{$channel->channel_code}/config.php");
        if(file_exists(Yii::getAlias("@app/config/payment/{$channel->channel_code}/config.php"))){
            $baseConfig = require $baseConfigFile;
        }
        $envFile = Yii::getAlias("@app/config/payment/{$channel->channel_code}/config_").strtolower(APPLICATION_ENV) .".php";
        if(file_exists($envFile)){
            $envConfig = require $envFile;
        }

        $appSecrets = $channelAccount->getAppSectets();
        if(empty($appSecrets) || !is_array($appSecrets) || empty($channelAccount->merchant_id)){
            throw new \app\common\exceptions\OperationFailureException("收款渠道配置错误:channelAccountId:{$channelAccount->id}",Macro::ERR_PAYMENT_CHANNEL_CONFIG);
        }
        $paymentConfig                = \yii\helpers\ArrayHelper::merge($baseConfig, $envConfig);

        $paymentConfig                = \yii\helpers\ArrayHelper::merge($paymentConfig, $appSecrets);
        $paymentConfig['merchantId'] = $channelAccount->merchant_id;
        $paymentConfig['appId']      = $channelAccount->app_id;
        $this->paymentConfig          = $paymentConfig;
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
    public static function md5Sign($params, $signKey){
        if (is_array($params)) {
            $a      = $params;
            $params = array();
            foreach ($a as $key => $value) {
                $params[] = "$key=$value";
            }
            sort($params,SORT_STRING);
            $params = implode('&', $params);
        } elseif (is_string($params)) {

        } else {
            return false;
        }

        $signStr = md5($params.'&key='.$signKey);
        //        Yii::debug(['md5Sign string: ',$signStr,$params]);
        return $signStr;
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
    public static function post($url, $postData, $header = [], $timeout = 5)
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
            $httpCode = $e->getCode();
            $body     = $e->getMessage();
        }

        Yii::info('request to channel: ' . $url . ' ' . $body);

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
    public static function httpGet($url, $headers=[])
    {
        try{
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);
            $httpCode = $response->getStatusCode();
            $body = (string)$response->getBody();
        }catch (\Exception $e){
            $httpCode = $e->getCode();
            $body = $e->getMessage();
        }

        Yii::debug('request to channel: '.$url.' '.$httpCode.' '.$body);

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
    public static function buildForm(array $params, string $url, bool $autoSubmit=true, $method='post', $buttonName='确定') {

        $sHtml = "<form id='allscoresubmit' name='allscoresubmit' action='".$url."' method='".$method."'>";

        foreach($params as $key=>$value){
            $sHtml.= "<input type='hidden' name='".$key."' value='".$value."'/>";
        }

        if($autoSubmit){
            $sHtml = $sHtml."<input type='submit' value='".$buttonName."' style='display:none;'></form>";
            $sHtml = $sHtml."<script>document.forms['allscoresubmit'].submit();</script>";
        }else{
            $sHtml = $sHtml."<input type='submit' value='".$buttonName."></form>";
        }

        return $sHtml;
    }

    /**
     * 解析异步通知请求，返回订单
     *
     * return array BasePayment::RECHARGE_NOTIFY_RESULT
     */
    public function parseNotifyRequest(array $request){
        //check sign

        //get order id and result from request

        throw new OperationFailureException("通道暂不支持解析异步通知", Macro::ERR_UNKNOWN);
    }

    /**
     * 解析同步通知请求，返回订单
     *
     * return array BasePayment::RECHARGE_NOTIFY_RESULT
     */
    public function parseReturnRequest(array $request){
        //check sign

        //get order id and result from request

        throw new OperationFailureException("通道暂不支持解析同步通知", Macro::ERR_UNKNOWN);
    }

     /**
      * 出款
      *
      * return array BasePayment::REMIT_RESULT
      */
    public function remit()
    {
        throw new OperationFailureException("通道暂不支持出款", Macro::ERR_UNKNOWN);
    }

    /**
     * 出款状态查询
     *
     * @return array BasePayment::REMIT_QUERY_RESULT
     */
    public function remitStatus(){

    }

    /**
      * 余额查询
      *
      * return  array BasePayment::BALANCE_QUERY_RESULT
      */
    public function balance()
    {
        throw new OperationFailureException("通道暂不支持查询余额", Macro::ERR_UNKNOWN);
    }


    /**
     * 网银支付
     */
    public function webBank()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 网银快捷支付
     */
    public function bankQuickPay()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信扫码支付
     */
    public function wechatQr()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信快捷扫码支付
     */
    public function wechatQuickQr()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 微信H5支付
     */
    public function wechatH5()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);

    }

    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 支付宝H5支付
     */
    public function alipayH5()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * QQ扫码支付
     */
    public function qqQr()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * QQ H5支付
     */
    public function qqH5()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 京东钱包支付
     */
    public function jdWallet()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

    /**
     * 银联微信扫码支付
     */
    public function unoinPayQr()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }


    /**
     * 京东H5支付
     */
    public function jdh5()
    {
        throw new OperationFailureException("通道暂不支持此支付方式:".__FUNCTION__, Macro::ERR_UNKNOWN);
    }

}