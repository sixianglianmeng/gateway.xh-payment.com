<?php
namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\Remit;
use app\common\models\model\User;
use app\components\Macro;
use app\components\Util;
use app\jobs\RemitQueryJob;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;

/**
 * 后台充值订单接口
 */
class RemitController extends BaseInnerController
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
     * 后台手工出款
     */
    public function actionAdd()
    {
        $merchantUsername = ControllerParameterValidator::getRequestParam($this->allParams, 'merchant_username', null,Macro::CONST_PARAM_TYPE_USERNAME,'充值账户错误');
        $rawRemits = ControllerParameterValidator::getRequestParam($this->allParams, 'remits', null,Macro::CONST_PARAM_TYPE_ARRAY,'提款列表错误');

        $totalAmount = 0;
        $remitCount = count($rawRemits);
        $isBatch = $remitCount>1;
        $batOrderNo = $isBatch?LogicRemit::generateBatRemitNo():'';
        $remits = $errRemits = $okRemits = [];

        foreach ($rawRemits as $i=>$remitArr) {
            //单笔大于5w的自动拆分
            if ($remitArr['amount'] >= 50000) {
                $minAmount    = 20000;
                $maxAmount    = 49999;
                $leftAmount   = $remitArr['amount'];
                $splitAmounts = [];
                if ($remitArr['amount'] > Remit::MAX_REMIT_PER_TIME) {
                    while ($leftAmount > 0) {
                        if ($maxAmount >= $leftAmount) {
                            $splitAmounts[] = $leftAmount;
                            break;
                        }

                        $per = mt_rand($minAmount,$maxAmount);//bcdiv(mt_rand($minAmount*10,$maxAmount*10),10,2);
                        $leftAmount=bcsub($leftAmount, $per,2);
                        $splitAmounts[] = $per;
                    }

                }

                Yii::info("{$merchantUsername}，{$remitArr['amount']},{$remitArr['bank_no']} split to: ".implode(',',$splitAmounts).' sum is:'.array_sum($splitAmounts));
                unset($rawRemits[$i]);
                foreach ($splitAmounts as $sa) {
                    $rawRemits[] = array_merge($remitArr,['amount'=>$sa]);
                }

            }
        }

        foreach ($rawRemits as $i=>$remitArr){
            $totalAmount=bcadd($totalAmount,$remitArr['amount'],6);

            if(
                empty($remitArr['amount'])
                || empty($remitArr['bank_code'])
                || empty($remitArr['bank_no'])
                || empty($remitArr['bank_account'])
//                || empty($remitArr['bank_province'])
//                || empty($remitArr['bank_city'])
//                || empty($remitArr['bank_branch'])

            ){
                $remitArr['msg'] = '银行信息不能为空';
                $errRemits[] = $remitArr;
                continue;
            }

            if($isBatch){
                $remitArr['bat_order_no'] = $batOrderNo;
                $remitArr['bat_index'] = $i+1;
                $remitArr['bat_count'] = $remitCount;
            }else{
                $remitArr['bat_order_no'] = '';
                $remitArr['bat_index'] = 0;
                $remitArr['bat_count'] = 0;
            }

            $remits[] = $remitArr;

        }

        //出款账户
        $merchant = User::findOne(['username'=>$merchantUsername]);
        if(empty($merchant)){
            return ResponseHelper::formatOutput(Macro::ERR_USER_NOT_FOUND,'用户不存在:'.$merchantUsername,['batOrderNo'=>$batOrderNo, 'errRemits'=>$rawRemits,
                                                                                     'okRemits'=>$okRemits]);
        }
        //初步余额检测
        if($merchant->balance<$totalAmount){
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,"账户余额($merchant->balance)小于总出款金额($totalAmount)",['batOrderNo'=>$batOrderNo, 'errRemits'=>$rawRemits,
                                                                                     'okRemits'=>$okRemits]);
        }

        $channelAccount = $merchant->paymentInfo->remitChannel;
        if(empty($channelAccount)){
            return ResponseHelper::formatOutput(Macro::ERR_REMIT_BANK_CONFIG,'用户出款通道未配置',['batOrderNo'=>$batOrderNo, 'errRemits'=>$rawRemits,
                                                                                     'okRemits'=>$okRemits]);
        }

        try{
            foreach ($remits as $remit){
                $request['trade_no'] = LogicRemit::generateMerchantRemitNo();
                $request['op_uid']              = $this->allParams['op_uid'] ?? 0;
                $request['op_username']         = $this->allParams['op_username'] ?? '';
                $request['client_ip']         = $this->allParams['op_ip'] ?? '';

                $request['bat_order_no'] = $remit['bat_order_no']??'';
                $request['bat_index'] = $remit['bat_index']??0;
                $request['bat_count'] = $remit['bat_count']??0;
                $request['bank_code'] = $remit['bank_code'];
                $request['account_name'] = $remit['bank_account'];
                $request['account_number'] = $remit['bank_no'];
                $request['order_amount'] = $remit['amount'];

                //生成订单
                $remit = LogicRemit::addRemit($request, $merchant, $channelAccount, true);
                $okRemits[] = [$remit->order_no];
            }
        }catch (\Exception $e){
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,'',['batOrderNo'=>$batOrderNo, 'errRemits'=>$errRemits, 'okRemits'=>$okRemits]);
        }


        return ResponseHelper::formatOutput(Macro::SUCCESS,'',['batOrderNo'=>$batOrderNo, 'errRemits'=>$errRemits, 'okRemits'=>$okRemits]);
    }

    /**
     * 后台同步出款状态
     */
    public function actionSyncStatus()
    {
        $inSeconds = ControllerParameterValidator::getRequestParam($this->allParams, 'inSeconds', '',Macro::CONST_PARAM_TYPE_INT_GT_ZERO,'时间秒数错误');
        $orderNoList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($inSeconds) && empty($orderNoList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $filter = ['status',[Remit::STATUS_CHECKED,Remit::STATUS_BANK_PROCESSING]];
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
        $remits = Remit::findAll($filter);
        foreach ($remits as $remit){
            Yii::info('remit status check: '.$remit->order_no);

            $job = new RemitQueryJob([
                'orderNo'=>$remit->order_no,
            ]);
            Yii::$app->remitQueryQueue->push($job);
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

        $orders = Remit::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            try{
                LogicRemit::setSuccess($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak);
            }catch (\Exception $ex){

            }
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }


    /**
     * 设置订单为失败
     */
    public function actionSetFail()
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

        $orders = Remit::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicRemit::setFailAndRefund($order,$bak,$this->allParams['op_uid'],$this->allParams['op_username']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /**
     * 设置订单为已审核
     */
    public function actionSetChecked()
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
        if(empty($opOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED,json_encode($rawOrderList));
        }

        $filter['order_no'] = array_keys($opOrderList);

        $orders = Remit::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicRemit::setChecked($order,$this->allParams['op_uid'],$this->allParams['op_username']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }
}
