<?php
namespace app\lib\payment;

use app\common\exceptions\OperationFailureException;
use app\common\models\model\Channel;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\components\Macro;

class ChannelPayment
{
    //三方渠道处理类
    protected $paymentHandle = null;

    public function __construct($order=null,$channelAccount=null,...$arguments)
    {
        if($order instanceof Order && $channelAccount){
            $this->setPaymentHandle($order,$channelAccount);
        }

        if($order instanceof Remit && $channelAccount){
            $this->setRemitHandle($order,$channelAccount);
        }

        if(empty($order) && $channelAccount){
            $this->setCommonHandle($channelAccount);
        }
    }

    public function setCommonHandle($channelAccount){

        $channel = $channelAccount->channel;

        if(empty($channel->remit_handle_class)){
            throw new OperationFailureException("渠道配置错误",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$channel->common_handle_class);
        $this->paymentHandle = new $handleClass();
        $this->paymentHandle->setPaymentConfig($channelAccount);
    }

    public function setPaymentHandle($order,$channelAccount){

        $channel = $channelAccount->channel;

        $payMethods = $channel->getPayMethods();
        if(empty($payMethods[$order->pay_method_code])){
            throw new OperationFailureException("渠道配置错误:未配置".Channel::getPayMethodsStr($order->pay_method_code)."对应Handle",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$payMethods[$order->pay_method_code]);
        $this->paymentHandle = new $handleClass($order,$channelAccount);
        $this->paymentHandle->setPaymentConfig($channelAccount);
        $this->paymentHandle->setOrder($order);
    }

    public function setRemitHandle($remit,$channelAccount){

        $channel = $channelAccount->channel;

        if(empty($channel->remit_handle_class)){
            throw new OperationFailureException("渠道配置错误,未配置对应remit_handle_class",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$channel->remit_handle_class);
        $this->paymentHandle = new $handleClass($remit,$channelAccount);
        $this->paymentHandle->setPaymentConfig($channelAccount);
        $this->paymentHandle->setRemit($remit);
    }

    /**
     * 魔术代理方法，如果调用的函数本类中有，者调用本类，否则调用对应的支付类方法。
     * 不同的支付方式对应到paymentHandle类中对应支付方式(Channel::ARR_METHOD_EN)的处理方法
     *
     * @param $method
     * @param $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if(!is_callable([$this->paymentHandle,$method])){
            throw new OperationFailureException("找不到处理方法:".class_basename($this->paymentHandle).'->'.$method,Macro::ERR_PAYMENT_CHANNEL_ID);
        }
        return $this->paymentHandle->$method(...$arguments);
    }
}