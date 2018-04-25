<?php
namespace app\lib\payment;

use app\components\Macro;

class ChannelPayment
{
    //三方渠道处理类
    protected $paymentHandle = null;

    public function __construct($order=null,$channelAccount=null,...$arguments)
    {
        if($order && $channelAccount){
            $this->setPaymentHandle($order,$channelAccount);
        }

    }

    public function setPaymentHandle($order,$channelAccount){

        $channel = $channelAccount->channel;

        $payMethods = $channel->getPayMethods();
        if(empty($payMethods[$order->pay_method_code])){
            throw new \Exception("渠道配置错误",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$payMethods[$order->pay_method_code]);
        $this->paymentHandle = new $handleClass($order,$channelAccount);
        $this->paymentHandle->setPaymentConfig($order,$channelAccount);
    }

    /**
     * 魔术代理方法，如果调用的函数本类中有，者调用本类，否则调用对应的支付类方法。
     *
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return $this->paymentHandle->$method(...$arguments);
    }
}