<?php
namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\Order;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
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
        $payeeName = ControllerParameterValidator::getRequestParam($this->allParams, 'merchant_username', null,Macro::CONST_PARAM_TYPE_USERNAME,'充值账户错误');
        $request['order_amount'] = ControllerParameterValidator::getRequestParam($this->allParams, 'amount', null,Macro::CONST_PARAM_TYPE_DECIMAL,'充值金额错误');
        $request['pay_type'] = ControllerParameterValidator::getRequestParam($this->allParams, 'pay_type', null,Macro::CONST_PARAM_TYPE_INT,'充值渠道错误');
        $request['bank_code'] = ControllerParameterValidator::getRequestParam($this->allParams, 'bank_code', '',Macro::CONST_PARAM_TYPE_INT,'银行代码错误');

        $merchant = User::findOne(['username'=>$payeeName]);
        if(empty($merchant)){
            Util::throwException(Macro::ERR_USER_NOT_FOUND);
        }
        $payMethod = $merchant->merchantPayment->getPayMethodById($payMethodId);
        if(empty($payMethod)){
            Util::throwException(Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        $request['merchant_order_no'] = LogicOrder::generateMerchantOrderNo();
        $request['op_uid']              = $this->allParams['op_uid'] ?? 0;
        $request['op_username']         = $this->allParams['op_username'] ?? '';
        $request['client_ip']         = $this->allParams['op_ip'] ?? '';
        //生成订单
        $order = LogicOrder::addOrder($request, $merchant, $payMethod);

        //生成跳转连接
        $payment = new ChannelPayment($order, $payMethod->channelAccount);
        $redirect = $payment->webBank();

        return ResponseHelper::formatOutput(Macro::SUCCESS,'',$redirect);
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

        $filter = ['!=','status',Order::STATUS_PAID];
        //最长一天
        if($inSeconds>14400) $inSeconds = 14400;
        if($inSeconds){
            $filter[] = ['>=','created_at',time()-$inSeconds];
        }
        if($orderNoList){
            foreach ($orderNoList as $k=>$on){
                if(!Util::validate($on,Macro::CONST_PARAM_TYPE_ORDER_NO)){
                    unset($orderNoList[$k]);
                }
            }

            $filter[] = ['order_no',$orderNoList];
        }
        $orders = Order::find($filter)->all();
        foreach ($orders as $order){
            LogicOrder::queryChannelOrderStatus($order);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'');
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

        $filter = ['!=','notify_status',Order::NOTICE_STATUS_SUCCESS];
        //最长一天
        if($inSeconds>14400) $inSeconds = 14400;
        if($inSeconds){
            $filter[] = ['>=','created_at',time()-$inSeconds];
        }
        if($orderNoList){
            foreach ($orderNoList as $k=>$on){
                if(!Util::validate($on,Macro::CONST_PARAM_TYPE_ORDER_NO)){
                    unset($orderNoList[$k]);
                }
            }

            $filter[] = ['order_no',$orderNoList];
        }
        $orders = Order::find($filter)->all();
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
}
