<?php
namespace app\modules\gateway\controllers\v1\server;

use app\common\models\model\Channel;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Order;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseServerSignedRequestController;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;

/*
 * 后台充值订单接口
 */
class OrderController extends BaseServerSignedRequestController
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
     * 支付宝-微信后台下单
     * 目前仅支持商银信，汇通
     */
    public function actionAlipayWechatOrder()
    {
        $needParams = ['merchant_code', 'order_no', 'pay_type', 'bank_code', 'order_amount', 'order_time', 'req_referer', 'customer_ip', 'notify_url', 'return_url', 'return_params', 'sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);
        if(!in_array($this->allParams['pay_type'],[Channel::METHOD_WECHAT,Channel::METHOD_ALIPAY])){
            Util::throwException(Macro::ERR_UNKNOWN,"此解开仅支持微信/支付宝");
        }

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
        if(!is_callable([$payment,'alipayWechatOrder'])){
            Util::throwException(Macro::ERR_UNKNOWN,"对不起,系统中此通道暂未支持此支付方式.");
        }
        $redirect = $payment->alipayWechatOrder();

        if($redirect['status'] != Macro::SUCCESS || empty($redirect['data']['formHtml'])){
            Util::throwException(Macro::ERR_UNKNOWN,"支付表单生成失败");
        }

        return $redirect['data']['url'];
    }

    /*
     * 订单状态查询
     */
    public function actionStatus()
    {
        $needParams = ['merchant_code', 'trade_no', 'order_no', 'sign'];
        $rules =     [
            'order_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
            'trade_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
        ];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams, $rules);

        $msg = '';
        $data = [];
        $ret = Macro::FAIL;
        $orderNo = $this->allParams['trade_no']??'';
        $merchantOrderNo = $this->allParams['order_no']??'';
        if(empty($orderNo) && empty($merchantOrderNo)){
            throw new InValidRequestException('请求参数错误');
        }

        //状态查询
        $order = LogicOrder::getStatus($orderNo, $merchantOrderNo, $this->merchant);

        if($order){
            $status = 'paying';
            if($order->status == Order::STATUS_PAID){
                $status = 'success';
            }elseif($order->status == Order::STATUS_FAIL){
                $status = 'failed';
            }
            $data = [
                'order_no'=>$order->merchant_order_no,
                'trade_no'=>$order->order_no,
                'merchant_code'=>$order->merchant_id,
                'trade_time'=>$order->created_at,
                'order_time'=>$order->merchant_order_time,
                'order_amount'=>$order->amount,
                'trade_status'=>$status,
            ];
            $ret = Macro::SUCCESS;
        }

        return ResponseHelper::formatOutput($ret,$msg,$data);
    }
}
