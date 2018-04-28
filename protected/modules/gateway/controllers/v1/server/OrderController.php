<?php
namespace app\modules\gateway\controllers\v1\server;

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
                'trade_time'=>date('Y-m-d H:i:s',$order->created_at),
                'order_time'=>date('Y-m-d H:i:s',$order->merchant_order_time),
                'order_amount'=>$order->amount,
                'trade_status'=>$status,
            ];
            $ret = Macro::SUCCESS;
        }

        return ResponseHelper::formatOutput($ret,$msg,$data);
    }
}
