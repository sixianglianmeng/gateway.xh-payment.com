<?php
namespace app\lib\payment;

use app\common\models\model\ChannelAccount;
use app\common\models\model\User;
use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use app\modules\gateway\models\model\Order;
use yii\base\Request;

class ChannelPayment
{
    //订单信息
    protected $order = null;
    //商户信息
    protected $merchant = null;
    //商户支付配置信息
    protected $paymentInfo = null;
    //三方渠道配置信息
    protected $channelAccount = null;

    public function __construct($order,$channelAccount)
    {
        $this->setOrder($order);
        $this->setChannelAccount($channelAccount);
    }

    public function setOrder(Order $order){
        $this->order = $order;
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

    public function parseNotifyRequest(Request $request){

    }

    public function parseReturnRequest(Request $request){

    }

    /*
     * 生成支付连接
     *
     * return array ['gatewayUrl'=>'','requestData'=>[],'requestMethod'=>'post']
     */
    public function createPaymentUrl(){
        $payMethods = $this->channelAccount->channel->getPayMethods();
        if(empty($payMethods[$this->order->pay_method_code])){
            throw new \Exception("渠道配置错误",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$payMethods[$this->order->pay_method_code]);

        $paymentHandle = new $handleClass($this->order,$this->channelAccount);
    }
}