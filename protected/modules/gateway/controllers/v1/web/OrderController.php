<?php
namespace app\modules\gateway\controllers\v1\web;

use app\common\models\model\User;
use app\components\Util;
use app\lib\payment\ChannelPayment;
use app\modules\gateway\controllers\v1\BaseWebSignedRequestController;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;
use app\modules\gateway\controllers\BaseController;

/*
 * 充值接口
 */
class OrderController extends BaseWebSignedRequestController
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
     * 充值
     */
    public function actionPay()
    {
        $needParams = ['merchant_code', 'order_no', 'pay_type', 'bank_code', 'order_amount', 'order_time', 'req_referer', 'customer_ip', 'notify_url', 'return_url', 'return_params', 'sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);


        $payMethod = $this->merchantPayment->getPayMethodById($orderData['pay_method_code']);
        if(empty($payMethod)){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }


        //生成订单
        $order = LogicOrder::addOrder($this->allParams,$this->merchant, $payMethod);

        //生成跳转连接
        $payment = new ChannelPayment($order, $payMethod->channelAccount);
        $redirect = $payment->createPaymentRedirectParams();

        //设置客户端唯一id
        $paymentRequest->setClientIdCookie();

        return $redirect['formHtml'];
    }
}
