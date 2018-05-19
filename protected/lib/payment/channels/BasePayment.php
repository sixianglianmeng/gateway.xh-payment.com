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
            throw new \Exception("找不到渠道配置文件",Macro::ERR_PAYMENT_CHANNEL_ID);
        }
        $baseConfig = require $baseConfigFile;
        $envConfig = [];
        $envFile = Yii::getAlias('@app/config/').strtolower(APPLICATION_ENV) .'/payment/allscore/config.php';
        if(file_exists($envFile)){
            $envConfig = require $envFile;
        }

        $appSecrets = $channelAccount->getAppSectets();
        if(empty($appSecrets) || empty($channelAccount->merchant_id)){
            throw new \Exception("收款渠道配置错误:caId:{$channelAccount->id}",Macro::ERR_PAYMENT_CHANNEL_CONFIG);
        }
        $paymentConfig = \yii\helpers\ArrayHelper::merge($baseConfig,$envConfig);
        $paymentConfig = \yii\helpers\ArrayHelper::merge($paymentConfig,$appSecrets);
        $this->paymentConfig = $paymentConfig;
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
//    public function createPaymentRedirectParams()
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