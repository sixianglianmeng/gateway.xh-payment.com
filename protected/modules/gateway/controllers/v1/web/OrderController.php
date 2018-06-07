<?php
namespace app\modules\gateway\controllers\v1\web;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\Channel;
use app\common\models\model\ChannelAccount;
use app\common\models\model\User;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ResponseHelper;
use app\lib\payment\ChannelPayment;
use app\lib\payment\channels\BasePayment;
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
     * 网银充值
     */
    public function actionWebBank()
    {
        $needParams = ['merchant_code', 'order_no', 'pay_type', 'bank_code', 'order_amount', 'order_time', 'req_referer', 'customer_ip', 'notify_url', 'return_url', 'return_params', 'sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);

        $payMethod = $this->merchantPayment->getPayMethodById($this->allParams['pay_type']);
        if(empty($payMethod) || empty($payMethod->channelAccount)){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }
        if($payMethod->channelAccount->status!=ChannelAccount::STATUS_ACTIVE && $payMethod->channelAccount->status!=ChannelAccount::STATUS_REMIT_BANED){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED,"支付渠道状态不正确:".$payMethod->channelAccount->getStatusStr());
        }

        //生成订单
        $order = LogicOrder::addOrder($this->allParams, $this->merchant, $payMethod);

        //生成跳转连接
        $payment = new ChannelPayment($order, $payMethod->channelAccount);
        $redirect = $payment->webBank();

        //设置客户端唯一id
        PaymentRequest::setClientIdCookie();

        if($redirect['status'] != Macro::SUCCESS || empty($redirect['data']['formHtml'])){
            Util::throwException(Macro::ERR_UNKNOWN,"支付表单生成失败");
        }

        return $redirect['data']['formHtml'];
    }

    /*
     * 收银台
     * 根据用户选择的通道自动跳转或展示支付二维码
     */
    public function actionCashier()
    {
        $needParams = ['merchant_code', 'order_no', 'pay_type', 'bank_code', 'order_amount', 'order_time', 'req_referer', 'customer_ip', 'notify_url', 'return_url', 'return_params', 'sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);

        $payMethod = $this->merchantPayment->getPayMethodById($this->allParams['pay_type']);

        if(empty($payMethod)){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED,'商户支付方式未配置');
        }
        if(empty($payMethod->channelAccount)){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED,'商户支付方式未绑定通道');
        }

        if($payMethod->channelAccount->status!=ChannelAccount::STATUS_ACTIVE && $payMethod->channelAccount->status!=ChannelAccount::STATUS_REMIT_BANED){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED,"支付渠道状态不正确:".$payMethod->channelAccount->getStatusStr());
        }

        //生成订单
        $order = LogicOrder::addOrder($this->allParams, $this->merchant, $payMethod);

        //生成订单之后进行多次随机跳转,最后再到三方支付
        $url = LogicOrder::generateRandRedirectUrl($order->order_no,mt_rand(2,5));

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($url);

        return $this->redirect($url, 302);
    }
}
