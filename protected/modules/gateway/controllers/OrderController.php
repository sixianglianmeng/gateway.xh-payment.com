<?php
namespace app\modules\gateway\controllers;

use app\common\models\model\User;
use app\components\Util;
use app\lib\payment\ChannelPayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;
use app\modules\gateway\controllers\BaseController;

/*
 * 微信后台接口
 */
class OrderController extends BaseController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        return parent::beforeAction($action);
    }

    /*
     * 解析响应微信验证文件
     */
    public function actionPay()
    {
        $needParams = ['merchant_code', 'order_no', 'pay_type', 'bank_code', 'order_amount', 'order_time', 'req_referer', 'customer_ip', 'notify_url', 'return_url', 'return_params', 'sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);

        //生成订单
        $order = LogicOrder::addOrder($this->allParams,$this->merchant,$this->merchantPayment);

        //生成跳转连接
//        $channelAccountInfo = $paymentRequest->getPaymentChannelAccount();
        //跳转
        $payment = new ChannelPayment($order,$this->merchantPayment->paymentChannel);
        $url = $payment->createPaymentRedirectParams();
        echo $url;
echo "<a href='$url' target='_blank'>充值</a>";

        //设置客户端唯一id
//        $paymentRequest->setClientIdCookie();
    }
}
