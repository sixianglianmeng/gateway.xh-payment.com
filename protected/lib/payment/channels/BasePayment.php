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

    /*
     * 根据订单和渠道账户配置设置支付配置
     *
     */
    public function setPaymentConfig($channelAccount)
    {
        $this->setChannelAccount($channelAccount);

        $channel = $channelAccount->channel;
        //渠道代码(英文)，用于配置文件目录名等。配置文件真实地址为/config/payment/目录名/config.php,且payment目录可放置于不同环境目录下。
        $baseConfigFile = Yii::getAlias("@app/config/payment/{$channel->channel_code}/config.php");
        if(!file_exists(Yii::getAlias("@app/config/payment/{$channel->channel_code}/config.php"))){
            throw new \app\common\exceptions\OperationFailureException("找不到渠道配置文件",Macro::ERR_PAYMENT_CHANNEL_ID);
        }
        $baseConfig = require $baseConfigFile;
        $envConfig = [];
        $envFile = Yii::getAlias('@app/config/').strtolower(APPLICATION_ENV) .'/payment/allscore/config.php';
        if(file_exists($envFile)){
            $envConfig = require $envFile;
        }

        $appSecrets = $channelAccount->getAppSectets();
        if(empty($appSecrets) || empty($channelAccount->merchant_id)){
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
     * @param sttring $url 请求地址
     * @param array $postData 请求数据
     *
     * @return bool|string
     */
    public static function post($url, $postData)
    {
        $headers = [];
        try{
            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $url, [
                'timeout' => 5,
                'body' => http_build_query($postData),
                'form_params' => ($postData),
            ]);

//            $response = $client->get($url.'?'.http_build_query($postData));
            $httpCode = $response->getStatusCode();
            $body = (string)$response->getBody();
        }catch (\Exception $e){
            $httpCode = $e->getCode();
            $body = $e->getMessage();
        }

        Yii::debug('request to channel: '.$url.' '.$body);

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

    /*
     * 解析异步通知请求，返回订单
     *
     * return app\common\models\model\Order
     */
//    public function parseNotifyRequest(array $request){
//        //check sign
//
//        //get order id from request
////        $orderId = $_REQUEST['orderId'];
////        //get order object and set order
////        $order = Order::findOne(['order_no'=>$orderId]);
////        $this->setOrder($order);
//    }

    /*
     * 解析同步通知请求，返回订单
     *
     * return app\common\models\model\Order
     */
//    public function parseReturnRequest(array $request){
//        //check sign
//
////        //get order id from request
////        $orderId = $_REQUEST['orderId'];
////        //get order object and set order
////        $order = Order::findOne(['order_no'=>$orderId]);
////        $this->setOrder($order);
//    }

    /*
     * 生成支付跳转参数连接
     *
     * return array ['url'=>'','htmlForm'=>'']
     */
//    public function webBank()
//    {
////        $this->setPaymentConfig($order, $channelAccount);
//        //具体不同支付方式生成支付参数业务逻辑
//    }

    /*
      * 提款待付
      *
      * return array
      */
//    public function remit()
//    {
//    }
    //
    /*
      * 余额查询
      *
      * return array
      */
//    public function remit()
//    {
//            return $ret['data']['balance']
//    }

}