<?php
namespace app\modules\gateway\controllers\v1\server;

Use Yii;
use app\common\models\model\Channel;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Order;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseServerSignedRequestController;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;

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
        //设置响应格式为商户接口json格式
        Yii::$app->response->format = Macro::FORMAT_JSON;
        Yii::$app->params['jsonFormatType'] = Macro::FORMAT_PAYMENT_GATEWAY_JSON;

        return parent::beforeAction($action);
    }

    /*
     * 后台下单
     */
    public function actionOrder()
    {
        $needParams = ['merchant_code', 'order_no', 'pay_type', 'bank_code', 'order_amount', 'order_time', 'customer_ip', 'notify_url', 'return_url', 'return_params', 'sign'];

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

        $data = [
            //收银台地址
            'url'          => LogicOrder::getCashierUrl($order->order_no),
            'trade_no'     => $order->order_no,
            'order_no'     => $order->merchant_order_no,
            'order_amount' => $order->amount,
        ];

        return ResponseHelper::formatOutput(Macro::SUCCESS,'下单成功',$data);
    }

    /*
     * 订单状态查询
     */
    public function actionStatus()
    {
        $needParams = ['merchant_code', 'trade_no', 'order_no', 'query_time', 'sign'];
        $rules =     [
            'order_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
            'trade_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
        ];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams, $rules);

        $msg = '订单查询成功';
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
                'trade_time'=>$order->paid_at,
                'order_time'=>$order->merchant_order_time,
                'order_amount'=>$order->amount,
                'trade_status'=>$status,
            ];
            $ret = Macro::SUCCESS;
        }else{
            $msg = '订单不存在';
        }

        return ResponseHelper::formatOutput($ret,$msg,$data);
    }
}
