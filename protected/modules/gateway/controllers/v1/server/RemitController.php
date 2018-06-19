<?php
namespace app\modules\gateway\controllers\v1\server;

use app\common\exceptions\InValidRequestException;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Remit;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseServerSignedRequestController;
use app\modules\gateway\models\logic\LogicRemit;
use app\modules\gateway\models\logic\PaymentRequest;

/*
 * 提现代付接口
 */
class RemitController extends BaseServerSignedRequestController
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
     * 单账户提现
     */
    public function actionSingle()
    {
       $needParams = ['merchant_code', 'trade_no', 'order_amount', 'order_time', 'bank_code', ' account_name', 'account_number',
            'bank_province','bank_city','bank_branch','sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);

        $paymentChannelAccount = $this->merchantPayment->remitChannel;
        if(!$paymentChannelAccount){
            throw new InValidRequestException('提款渠道配置错误');
        }
        if($paymentChannelAccount->status!=ChannelAccount::STATUS_ACTIVE && $paymentChannelAccount->status!=ChannelAccount::STATUS_RECHARGE_BANED){
            Util::throwException(Macro::ERR_REMIT_CHANNEL_NOT_ENOUGH,"出款渠道状态不正确:".$paymentChannelAccount->getStatusStr());
        }
        //生成订单
        $remit = LogicRemit::addRemit($this->allParams,$this->merchant,$paymentChannelAccount);
        $remit = LogicRemit::processRemit($remit,$paymentChannelAccount);
        $msg = '';
        $data = [
            'transid'=>$remit->merchant_order_no,
            'order_id'=>$remit->order_no,
            'bank_status'=>$remit->bank_status,
        ];
        if($remit->bank_status!==Remit::BANK_STATUS_SUCCESS){
            $msg = $remit->fail_msg;
        }
        return ResponseHelper::formatOutput(0,$msg,$data);
    }


    /*
     * 提款状态查询
     */
    public function actionStatus()
    {
        $needParams = ['merchant_code', 'trade_no', 'order_no', 'now_date','sign'];
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
        $orderNo = $this->allParams['order_no']??'';
        $merchantOrderNo = $this->allParams['trade_no']??'';
        if(empty($orderNo) && empty($merchantOrderNo)){
            throw new InValidRequestException('请求参数错误');
        }

        //状态查询
        $remit = LogicRemit::getStatus($orderNo, $merchantOrderNo, $this->merchant);

        if($remit){
            $data = [
                'transid'=>$remit->merchant_order_no,
                'order_id'=>$remit->order_no,
                'bank_status'=>$remit->bank_status,
            ];
            $ret = Macro::SUCCESS;
        }

        return ResponseHelper::formatOutput($ret,$msg,$data);
    }
}
