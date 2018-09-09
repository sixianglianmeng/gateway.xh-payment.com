<?php
namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\LogApiRequest;
use app\common\models\model\Order;
use app\common\models\model\User;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
use app\lib\payment\ChannelPayment;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicOrder;
use Yii;

/**
 * 后台充值订单接口
 */
class OrderController extends BaseInnerController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        return parent::beforeAction($action);
    }

    /**
     * 手工充值
     */
    public function actionAdd()
    {
        $payeeName = ControllerParameterValidator::getRequestParam($this->allParams, 'merchant_username', null,Macro::CONST_PARAM_TYPE_USERNAME,'充值账户格式错误');
        $request['order_amount'] = ControllerParameterValidator::getRequestParam($this->allParams, 'amount', null,Macro::CONST_PARAM_TYPE_DECIMAL,'充值金额格式错误');
        $request['pay_type'] = ControllerParameterValidator::getRequestParam($this->allParams, 'pay_type', null,Macro::CONST_PARAM_TYPE_ALNUM,'充值渠道格式错误');
        $request['bank_code'] = ControllerParameterValidator::getRequestParam($this->allParams, 'bank_code', '',Macro::CONST_PARAM_TYPE_INT,'银行代码格式错误');
        $type = ControllerParameterValidator::getRequestParam($this->allParams, 'type', 0,Macro::CONST_PARAM_TYPE_INT,'订单类型错误');
        $bak = ControllerParameterValidator::getRequestParam($this->allParams, 'bak', '',Macro::CONST_PARAM_TYPE_STRING,'备注错误');

        $merchant = User::findOne(['username'=>$payeeName]);
        if(empty($merchant)){
            Util::throwException(Macro::ERR_USER_NOT_FOUND);
        }

        $payMethod = $merchant->paymentInfo->getPayMethodById($request['pay_type']);
        if(empty($payMethod)){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        $request['order_no']    = LogicOrder::generateMerchantOrderNo();
        $request['op_uid']      = $this->allParams['op_uid'] ?? 0;
        $request['op_username'] = $this->allParams['op_username'] ?? '';
        $request['client_ip']   = $this->allParams['op_ip'] ?? '';
        $request['order_time']  = time();
        $request['type']  = $type;
        $request['bak']  = $bak;
        //生成订单
        $order = LogicOrder::addOrder($request, $merchant, $payMethod);

        $data = [
            'order_no'=>$order->order_no,
            'cashier_url'=>Yii::$app->request->hostInfo."/order/pay.html?orderNo=".$order->order_no,
        ];
        return ResponseHelper::formatOutput(Macro::SUCCESS,'', $data);
    }

    /**
     * 到三方同步订单状态
     */
    public function actionSyncStatus()
    {
        $inSeconds = ControllerParameterValidator::getRequestParam($this->allParams, 'inSeconds', '',Macro::CONST_PARAM_TYPE_INT_GT_ZERO,'时间秒数错误');
        $orderNoList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($inSeconds) && empty($orderNoList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $query =  Order::find()
            ->andWhere(['!=','status',Order::STATUS_PAID]);

        //最长一天
        if($inSeconds>14400) $inSeconds = 14400;
        if($inSeconds){
            $query->andWhere(['>=','created_at',time()-$inSeconds]);
        }
        if($orderNoList){
            foreach ($orderNoList as $k=>$on){
                if(!Util::validate($on,Macro::CONST_PARAM_TYPE_ORDER_NO)){
                    unset($orderNoList[$k]);
                }
            }
            $query->andWhere(['order_no'=>$orderNoList]);
        }
        $orders = $query->all();

        foreach ($orders as $order){
            LogicOrder::queryChannelOrderStatus($order);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'');
    }


    /**
     * 到三方同步查询订单状态
     */
    public function actionSyncStatusRealtime()
    {
        $orderNo = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNo', null,Macro::CONST_PARAM_TYPE_ORDER_NO,'订单号列表错误');


        $order =  Order::findOne(['order_no'=>$orderNo]);
        if(!$order){
            return ResponseHelper::formatOutput(Macro::FAIL,'订单不存在');
        }

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->order_no,
            'merchant_order_no'=>$order->merchant_order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_QUERY,
            'merchant_id'=>$order->channel_merchant_id,
            'merchant_name'=>$order->channelAccount->merchant_account,
            'channel_account_id'=>$order->channel_account_id,
            'channel_name'=>$order->channelAccount->channel_name,
        ];

        $paymentChannelAccount = $order->channelAccount;
        $payment = new ChannelPayment($order, $paymentChannelAccount);
        $ret = $payment->orderStatus();

        $msg = '';
        if($ret['status'] === Macro::SUCCESS){
            switch ($ret['data']['trade_status']){
                case Order::STATUS_PAID:
                case Order::STATUS_SETTLEMENT:
                    if($ret['data']['amount']>0){
                        $msg = "付款成功,付款金额{$ret['data']['amount']},上游订单号{$ret['data']['channel_order_no']}";
                    }

                    break;
                case Order::STATUS_NOTPAY:
                    $msg = '订单待付款';
                    break;
                case Order::STATUS_FAIL:
                    $msg = $ret['message']?$ret['message']:'上游返回返回订单失败';
                    break;
            }
        }
        //失败
        else{
            $msg = "订单查询失败.".($ret['message']??'');
        }
        $msg = '本地状态:'.$order->getStatusStr()."\n上游状态:".$msg;
        if(!empty($ret['data']['rawMessage'])){
            $msg.=" \n原始消息: ".$ret['data']['rawMessage'];
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,$msg);
    }

    /**
     * 发送订单通知
     */
    public function actionSendNotify()
    {
        $inSeconds = ControllerParameterValidator::getRequestParam($this->allParams, 'inSeconds', '',Macro::CONST_PARAM_TYPE_INT_GT_ZERO,'时间秒数错误');
        $orderNoList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($inSeconds) && empty($orderNoList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $query =  Order::find()
            ->andWhere(['status'=>[Order::STATUS_PAID,Order::STATUS_SETTLEMENT]])
            ->andWhere(['!=','notify_status',Order::NOTICE_STATUS_SUCCESS]);

        //最长一天
        if($inSeconds>14400) $inSeconds = 14400;
        if($inSeconds){
            $query->andWhere(['>=','created_at',time()-$inSeconds]);
        }
        if($orderNoList){
            foreach ($orderNoList as $k=>$on){
                if(!Util::validate($on,Macro::CONST_PARAM_TYPE_ORDER_NO)){
                    unset($orderNoList[$k]);
                }
            }
            $query->andWhere(['order_no'=>$orderNoList]);
        }
        $orders = $query->all();

        foreach ($orders as $order){
            LogicOrder::notify($order);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'');
    }

    /**
     * 设置订单为成功
     */
    public function actionSetSuccess()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        Yii::info($rawOrderList);
        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
       foreach ($rawOrderList as $k=>$on){
           if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
               $opOrderList[$on['order_no']] = $on;
           }
       }
       if(empty($opOrderList)){
           Util::throwException(Macro::PARAMETER_VALIDATION_FAILED,json_encode($rawOrderList));
       }

        $filter['order_no'] = array_keys($opOrderList);

        $orders = Order::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicOrder::paySuccess($order,$order->amount,$bak,$this->allParams['op_uid'],$this->allParams['op_username']);
            LogicOrder::notify($order);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /**
     * 冻结订单
     */
    public function actionFrozen()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
        foreach ($rawOrderList as $k=>$on){
            if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
                $opOrderList[$on['order_no']] = $on;
            }
        }

        $filter['order_no'] = array_keys($opOrderList);

        $orders = Order::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicOrder::frozen($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak,$this->allParams['op_ip']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }


    /**
     * 解冻订单
     */
    public function actionUnFrozen()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
        foreach ($rawOrderList as $k=>$on){
            if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
                $opOrderList[$on['order_no']] = $on;
            }
        }

        $filter['order_no'] = array_keys($opOrderList);

        $orders = Order::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicOrder::unfrozen($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak,$this->allParams['op_ip']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /**
     * 订单结算
     */
    public function actionSettlement()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'idList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');
        $bak = ControllerParameterValidator::getRequestParam($this->allParams, 'bak','',Macro::CONST_PARAM_TYPE_STRING,'结算原因错误',[1]);

        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
        foreach ($rawOrderList as $k=>$on){
            $opOrderList[] = intval($on);
        }

        $filter['id'] = $opOrderList;

        $orders = Order::findAll($filter);
        foreach ($orders as $order){
            LogicOrder::settlement($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak,$this->allParams['op_ip']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /**
     * 订单退款
     */
    public function actionRefund()
    {
        $orderNo = ControllerParameterValidator::getRequestParam($this->allParams, 'order_no', null,Macro::CONST_PARAM_TYPE_ORDER_NO,'订单号格式错误');
        $bak = ControllerParameterValidator::getRequestParam($this->allParams, 'bak',null,Macro::CONST_PARAM_TYPE_STRING,'退款原因错误',[1]);

        $filter = ['order_no'=>$orderNo];

        $order = Order::findOne($filter);
        if(empty($order)){
            Util::throwException(Macro::FAIL,'订单不存在');
        }
        if($order->status!=Order::STATUS_SETTLEMENT){
            Util::throwException(Macro::FAIL,'只有已结算订单才能退款');
        }

        LogicOrder::refund($order,$bak,$this->allParams['op_ip'],$this->allParams['op_uid'],$this->allParams['op_username']);

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }
}
