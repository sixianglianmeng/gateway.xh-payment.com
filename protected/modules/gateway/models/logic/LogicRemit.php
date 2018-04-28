<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\UserPaymentInfo;
use app\jobs\PaymentNotifyJob;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ObjectNoticeResult;
use app\common\models\model\Remit;
use Yii;
use app\common\models\model\User;
use app\components\Macro;

class LogicRemit
{
    //通知失败后间隔通知时间
    const NOTICE_DELAY = 300;

    static public function addRemit(array $request, User $merchant, UserPaymentInfo $merchantPayment){
//        ['merchant_code', 'trade_no', 'order_amount', 'order_time', 'bank_code', ' account_name', 'account_number',
        $remitData = [];
        $remitData['order_no'] = self::generateRemitNo();
        $remitData['bat_order_no'] = $request['bat_order_no']??'';
        $remitData['bank_code'] = $request['bank_code'];
        $remitData['bank_account'] = $request['account_name'];
        $remitData['bank_no'] = $request['account_number'];
        $remitData['merchant_id'] = $request['merchant_code'];
        $remitData['merchant_order_no'] = $request['trade_no'];
        $remitData['amount'] = $request['order_amount'];
        $remitData['remit_fee'] = $merchant->remit_fee;
        $remitData['client_ip'] = $request['client_ip']??'';

        $remitData['app_id'] = $request['merchant_code'];
        $remitData['status'] = Remit::STATUS_CHECKED;

        $remitData['merchant_account'] = $merchant->username;
        $channelInfo = $merchantPayment->paymentChannel;

        $remitData['channel_id'] = $channelInfo->channel_id;
        $remitData['channel_merchant_id'] = $channelInfo->merchant_id;
        $remitData['channel_app_id'] = $channelInfo->app_id;
        $remitData['created_at'] = time();

        $hasRemit = Remit::findOne(['app_id'=>$remitData['app_id'],'merchant_order_no'=>$request['trade_no']]);
        if($hasRemit){
//            throw new InValidRequestException('请不要重复下单');
            return $hasRemit;
        }

        $newRemit = new Remit();
        $newRemit->setAttributes($remitData,false);
        $newRemit->save();

        return $newRemit;
    }

    static public function processRemit($remit, ChannelAccount $paymentChannel){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no]);
        switch ($remit->status){
            case Remit::STATUS_CHECKED:
                $remit = self::deduct($remit);
                break;
            case Remit::STATUS_DEDUCT:
                $remit = self::commitToBank($remit);
                break;
            default:
                break;
        }

        return $remit;
    }

    static public function deduct(Remit $remit){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no]);
        //账户余额扣款
        if($remit->status == Remit::STATUS_CHECKED){
            //账户扣款
            $logicUser = new LogicUser($remit->merchant);
            $amount =  0-$remit->amount;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_WITHDRAWAL, $remit->order_no, Yii::$app->request->userIP);
            //手续费
            $amount =  0-$remit->remit_fee;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_WITHDRAWAL_FEE, $remit->order_no, Yii::$app->request->userIP);

            $remit->status = Remit::STATUS_DEDUCT;
            $remit->save();

            return $remit;
        }else{
            throw new \Exception('订单未审核，无法扣款');
        }
    }

    static public function commitToBank(Remit $remit, ChannelAccount $paymentChannel){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no]);
        if($remit->status == Remit::STATUS_DEDUCT){
            //提交到银行
            //银行状态说明：00处理中，04成功，05失败或拒绝
            $payment = new ChannelPayment($remit, $paymentChannel);
            $ret = $payment->remit();
            if($ret['code'] === 0 && $ret['data']['status']=='00'){
                $remit->status = Remit::STATUS_BANK_PROCESSING;

                $remit->status = $ret['order_id'];
                $remit->save();
            }
            //失败或者银行拒绝，退款
            else{
                $remit = self::setFail($remit, $ret['message']);

                $remit = self::refund($remit);
            }

            return $remit;
        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$remit->order_no,"订单状态错误，无法提交到银行:".$remit->status]);
            throw new \Exception('订单状态错误，无法提交到银行');
        }
    }

    static public function refund($remit){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no]);
        if(
            $remit->status == Remit::STATUS_BANK_PROCESS_FAIL
            || $remit->status == Remit::STATUS_BANK_NET_FAIL
        ){
            //退回账户扣款
            $logicUser = new LogicUser($remit->merchant);
            $amount =  $remit->amount;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_WITHDRAWAL, $remit->order_no, Yii::$app->request->userIP);
            //退回手续费
            $amount =  $remit->remit_fee;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_WITHDRAWAL_FEE, $remit->order_no, Yii::$app->request->userIP);

            $remit->status = Remit::STATUS_REFUND;
            $remit->save();

            return $remit;
        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$remit->order_no,"订单状态错误，无法退款:".$remit->status]);
            throw new \Exception('订单状态错误，无法退款');
        }
    }

    static public function generateRemitNo(){
        return 'R'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function getRemitByRemitNo($orderNo){
        $order = Remit::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    static public function getPaymentChannelAccount(Remit $order)
    {
        $channel = ChannelAccount::findOne([
            'channel_id'=>$order->channel_id,
            'merchant_id'=>$order->channel_merchant_id,
            'app_id'=>$order->channel_app_id,
        ]);

        if(empty($channel)){
            throw new InValidRequestException('无法根据订单查找支付渠道信息');
        }

        return $channel;
    }

    static public function processChannelNotice(ObjectNoticeResult $noticeResult){
        if(
//            $noticeResult->status !== Macro::SUCCESS
            !$noticeResult->order
//            || !$noticeResult->amount
        ){
            throw new InValidRequestException('支付结果对象错误',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
        }

        $order = $noticeResult->order;
        //未处理
        if( $noticeResult->status === Macro::SUCCESS && $order->status !== Remit::STATUS_PAID){
            $order = self::paySuccess($order,$noticeResult->amount,$noticeResult->channelRemitNo);

            $order = self::bonus($order);
        }
        elseif( $noticeResult->status === Macro::FAIL){
            $order = self::payFail($order,$noticeResult->msg);
            Yii::debug([__FUNCTION__,'order not paid',$noticeResult->orderNo]);
        }

        if($order->notify_status != Remit::NOTICE_STATUS_SUCCESS){
            self::notify($order);
        }
    }

    /*
     * 订单失败
     *
     * @param Remit $remit 订单对象
     * @param String $failMsg 失败描述信息
     */
    static public function setFail(Remit $remit, $failMsg='')
    {
        $remit->fail_msg = $failMsg;
        $remit->status = Remit::STATUS_BANK_PROCESS_FAIL;
        $remit->save();

        $remit = self::refund($remit);

        return $remit;
    }
}