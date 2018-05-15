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

/*
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

    /*
     * 发送订单通知
     */
    public function actionSyncStatus()
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
        $orders = Order::findAll($filter);
        foreach ($orders as $order){
            LogicOrder::notify($order);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'');
    }

    /*
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
            $bak = $opOrderList[$order->order_no]['bak']??'admin_'.$this->allParams['op_username'].'_'.date('ymdHis');
            LogicOrder::paySuccess($order,$order->amount,$bak,$this->allParams['op_uid'],$this->allParams['op_username']);
            LogicOrder::notify($order);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /*
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
            $bak = $opOrderList[$order->order_no]['bak']??'admin_'.$this->allParams['op_username'].'_'.date('ymdHis');
            LogicOrder::frozen($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak,$this->allParams['op_ip']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }


    /*
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
            $bak = $opOrderList[$order->order_no]['bak']??'admin_'.$this->allParams['op_username'].'_'.date('ymdHis');
            LogicOrder::unfrozen($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak,$this->allParams['op_ip']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }
}
