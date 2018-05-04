<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\UserPaymentInfo;
use app\jobs\PaymentNotifyJob;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ChannelPayment;
use app\lib\payment\ObjectNoticeResult;
use app\common\models\model\Remit;
use power\yii2\exceptions\ParameterValidationExpandException;
use Yii;
use app\common\models\model\User;
use app\components\Macro;

class LogicRemit
{
    //通知失败后间隔通知时间
    const NOTICE_DELAY = 300;
    const REDIS_CACHE_KEY = 'lt_remit';

    static public function addRemit(array $request, User $merchant, ChannelAccount $paymentChannelAccount){
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
        $remitData['bank_status'] = Remit::BANK_STATUS_NONE;

        $remitData['merchant_account'] = $merchant->username;

        $remitData['channel_account_id'] = $paymentChannelAccount->id;
        $remitData['channel_id'] = $paymentChannelAccount->channel_id;
        $remitData['channel_merchant_id'] = $paymentChannelAccount->merchant_id;
        $remitData['channel_app_id'] = $paymentChannelAccount->app_id;
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

    static public function processRemit($remit, ChannelAccount $paymentChannelAccount){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no,$remit->status]);
        switch ($remit->status){
            case Remit::STATUS_CHECKED:
                $remit = self::deduct($remit);
                $remit = self::commitToBank($remit,$paymentChannelAccount);
                break;
            case Remit::STATUS_DEDUCT:
                $remit = self::commitToBank($remit,$paymentChannelAccount);
                break;
            case Remit::STATUS_BANK_PROCESS_FAIL:
            case Remit::STATUS_BANK_NET_FAIL:
                $remit = self::refund($remit);
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
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REMIT, $remit->order_no, Yii::$app->request->userIP);
            //手续费
            $amount =  0-$remit->remit_fee;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REMIT_FEE, $remit->order_no, Yii::$app->request->userIP);

            $remit->status = Remit::STATUS_DEDUCT;
            $remit->save();

            return $remit;
        }else{
            throw new \Exception('订单未审核，无法扣款');
        }
    }

    static public function commitToBank(Remit $remit, ChannelAccount $paymentChannelAccount){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no]);
        if($remit->status == Remit::STATUS_DEDUCT){
            //提交到银行
            //银行状态说明：00处理中，04成功，05失败或拒绝
            $payment = new ChannelPayment($remit, $paymentChannelAccount);
            $ret = $payment->remit();
            Yii::info('remit commitToBank: '.json_encode($ret,JSON_UNESCAPED_UNICODE));
            if($ret['code'] === 0){
                switch ($ret['data']['status']){
                    case '00':
                        $remit->status = Remit::STATUS_BANK_PROCESSING;
                        $remit->bank_status =  Remit::BANK_STATUS_PROCESSING;
                        $remit->channel_order_no = $ret['order_id'];
                    case '04':
                        $remit->status = Remit::STATUS_SUCCESS;
                        $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
                    case '05':
                        $remit->status = Remit::STATUS_BANK_PROCESS_FAIL;
                        $remit->bank_status =  Remit::BANK_STATUS_FAIL;
                }

                if(!empty($ret['order_id']) && empty($remit->channel_order_no)){
                    $remit->channel_order_no = $ret['order_id'];
                }
                $remit->save();
            }
            //失败或者银行拒绝，退款
            else{
                $remit = self::setFail($remit, $ret['message']);
            }

            return $remit;

        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$remit->order_no,"订单状态错误，无法提交到银行:".$remit->status]);
            throw new \Exception('订单状态错误，无法提交到银行');
        }
    }

    static public function queryChannelRemitStatus(Remit $remit, ChannelAccount $paymentChannelAccount){
        Yii::debug([__CLASS__.':'.__FUNCTION__,$remit->order_no]);
        //提交到银行
        //银行状态说明：00处理中，04成功，05失败或拒绝
        $payment = new ChannelPayment($remit, $paymentChannelAccount);
        $ret = $payment->remitStatus();
        Yii::info('remit status check: '.json_encode($ret,JSON_UNESCAPED_UNICODE));
        if($ret['code'] === 0){
            switch ($ret['data']['status']){
                case '00':
                    $remit->status = Remit::STATUS_BANK_PROCESSING;
                    $remit->bank_status =  Remit::BANK_STATUS_PROCESSING;
                    $remit->channel_order_no = $ret['order_id'];
                case '04':
                    $remit->status = Remit::STATUS_SUCCESS;
                    $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
                case '05':
                    $remit->status = Remit::STATUS_BANK_PROCESS_FAIL;
                    $remit->bank_status =  Remit::BANK_STATUS_FAIL;
            }

            if(!empty($ret['order_id']) && empty($remit->channel_order_no)){
                $remit->channel_order_no = $ret['order_id'];
            }
            $remit->save();

            self::processRemit($remit, $paymentChannelAccount);
        }
        //失败
        else{

        }

        return $remit;
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
            $ip = Yii::$app->request->userIP??'';
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REFUND_REMIT, $remit->order_no, $ip);
            //退回手续费
            $amount =  $remit->remit_fee;

            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REFUND_REMIT_FEE, $remit->order_no, $ip);

            $remit->status = Remit::STATUS_REFUND;
            $remit->save();

            return $remit;
        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$remit->order_no,"订单状态错误，无法退款:".$remit->status]);
            throw new \Exception('订单状态错误，无法退款:'.$remit->status);
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

    /*
     * 订单成功
     *
     * @param Remit $remit 订单对象
     */
    static public function setSuccess(Remit $remit)
    {
        $remit->status = Remit::STATUS_SUCCESS;
        $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
        $remit->save();

        self::updateToRedis($remit);

        return $remit;
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
        $remit->bank_status =  Remit::BANK_STATUS_FAIL;
        $remit->save();

        self::updateToRedis($remit);

        $remit = self::refund($remit);

        return $remit;
    }

    /**
     * 更新订单信息到redis
     *
     * @param
     * @return
     */
    public static function updateToRedis($remit)
    {
        $data = [
            'merchant_order_no'=>$remit->merchant_order_no,
            'order_no'=>$remit->order_no,
            'bank_status'=>$remit->bank_status,
        ];
        $json = \GuzzleHttp\json_encode($data);
        Yii::$app->redis->hmset(self::REDIS_CACHE_KEY, $remit->order_no, $json);
    }

    /**
     * 获取订单状态
     *
     */
    public static function getStatus($orderNo = '',$merchantOrderNo = '', $merchant)
    {
        if($merchantOrderNo && !$orderNo){
            $remit = Remit::findOne(['merchant_order_no'=>$merchantOrderNo,'merchant_id'=>$merchant->id]);
            if($remit) $orderNo = $remit->order_no;
        }

        if(!$orderNo){
            throw new ParameterValidationExpandException('参数错误');
        }

        $statusJson = Yii::$app->redis->hmget(self::REDIS_CACHE_KEY, $orderNo);

        $statusArr = [];
        if(!empty($statusJson[0])){
            $statusArr = \GuzzleHttp\json_decode($statusJson[0]);
        }

        return $statusArr;
    }
}