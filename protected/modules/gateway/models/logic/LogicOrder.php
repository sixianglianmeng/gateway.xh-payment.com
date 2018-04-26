<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\UserPaymentInfo;
use app\lib\payment\ObjectNoticeResult;
use Yii;
use app\common\models\model\User;
use app\common\models\model\Order;
use app\components\Macro;

class LogicOrder
{
    static public function addOrder(array $request, User $merchant, $merchantPayment){
        //array(12) { ["notify_url"]=> string(26) "http://127.0.0.1/Demo_PHP/" ["return_url"]=> string(26) "http://127.0.0.1/Demo_PHP/" ["pay_type"]=> string(1) "1" ["bank_code"]=> string(3) "ABC" ["merchant_code"]=> string(5) "10000" ["order_no"]=> string(18) "P18042323131198565" ["order_amount"]=> string(3) "0.1" ["order_time"]=> string(19) "2018-04-23 23:13:13" ["req_referer"]=> string(9) "127.0.0.1" ["customer_ip"]=> string(9) "127.0.0.1" ["return_params"]=> string(12) "0|EF9012AB21" ["sign"]=> string(32) "12d368cf2f379afe3754e911d1f9545f" }

        //op_uid,op_username,order_no,merchant_order_no,channel_order_no,merchant_id,app_id,app_name,merchant_account,amount,paid_amount,channel_id,channel_merchant_id,pay_method_code,sub_pay_method_code,title,notify_status,notify_url,reutrn_url,client_ip,created_at,paid_at,updated_at,bak,notify_at,notify_times,next_notify_time,status,return_params

        $orderData = [];
        $orderData['order_no'] = self::generateOrderNo();
        $orderData['pay_method_code'] = $request['pay_type'];
        $orderData['notify_url'] = $request['notify_url'];
        $orderData['return_url'] = $request['return_url'];
        $orderData['bank_code'] = $request['bank_code'];
        $orderData['merchant_id'] = $request['merchant_code'];
        $orderData['merchant_order_no'] = $request['order_no'];
        $orderData['amount'] = $request['order_amount'];
        $orderData['client_ip'] = Yii::$app->request->userIP;
        $orderData['return_params'] = $request['return_params'];

        $orderData['app_id'] = $request['merchant_code'];
        $orderData['status'] = Order::STATUS_NOTPAY;
        $orderData['financial_status'] = Order::FINANCIAL_STATUS_NONE;
        $orderData['notify_status'] = Order::NOTICE_STATUS_NONE;

        $orderData['merchant_account'] = $merchant->username;
        $channelInfo = $merchantPayment->paymentChannel;

        $orderData['channel_id'] = $channelInfo->channel_id;
        $orderData['channel_merchant_id'] = $channelInfo->merchant_id;
        $orderData['channel_app_id'] = $channelInfo->app_id;

        $hasOrder = Order::findOne(['app_id'=>$orderData['app_id'],'merchant_order_no'=>$request['order_no']]);
        if($hasOrder){
//            throw new InValidRequestException('请不要重复下单');
            return $hasOrder;
        }

        $newOrder = new Order();
        $newOrder->setAttributes($orderData,false);
        $newOrder->save();

        return $newOrder;
    }

    static public function generateOrderNo(){
        return 'P'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function getOrderByOrderNo($orderNo){
        $order = Order::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }


    static public function getPaymentChannelAccount(Order $order)
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
        if($noticeResult->status !== Macro::SUCCESS
            || !$noticeResult->order
            || !$noticeResult->amount
        ){
            throw new InValidRequestException('支付结果对象错误',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
        }

        $order = $noticeResult->order;
        //已经支付
        if($order->status === Order::STATUS_PAID){
            //TODO: 订单成功，通知未成功，进行一次通知？
            if($order->notify_status !== Order::NOTICE_STATUS_SUCCESS){
                self::finishOrder($order,$noticeResult->amount,$noticeResult->channelOrderNo);
            }

            throw new InValidRequestException('订单已经处理，请不要重复刷新',Macro::ERR_PAYMENT_ALREADY_DONE);
        }



    }

    /*
     * 订单支付成功
     *
     * @param Order $order 订单对象
     * @param Decimal $paidAmount 实际支付金额
     * @param String $channelOrderNo 第三方流水号
     */
    static public function finishOrder(Order $order,$paidAmount,$channelOrderNo){

        //更改订单状态
        $order->paid_money = $paidAmount;
        $order->channel_order_no = $channelOrderNo;
        $order->status = Order::STATUS_PAID;
        $order->paid_at = time();
        $order->save();
    }

    /*
     * 订单分红
     */
    static public function bonus(Order $order){

        $user = $order->getMerchant();
        $logicUser = new LogicUser($user);
        bcscale(6);
        //商户需扣除手续费
        $rechargeFee =  0-bcmul(bcsub(1,$user->recharge_rate),$order->paid_money);

        $logicUser->changeUserBalance($order->paid_money, Financial::EVENT_TYPE_RECHARGE, $order->order_no, Yii::$app->request->userIP);
        $logicUser->changeUserBalance($rechargeFee, Financial::EVENT_TYPE_RECHARGE_FEE, $order->order_no, Yii::$app->request->userIP);

        //逐级返点给上级代理
        $parentIds = $user->getAllParentAgentId();
        foreach ($parentIds as $pid){
            $pUser = User::findActive($pid);
            if(!empty($pUser)){
                $logicUser =  new LogicUser($pUser);
                 $rechargeFee =  bcmul($user->recharge_parent_rebate_rate,$order->paid_money);
                $logicUser->changeUserBalance($rechargeFee, Financial::EVENT_TYPE_BONUS, $order->order_no, Yii::$app->request->userIP);
            }
        }

//recharge_rate
    }

    /*
     * 生成订单同步通知跳转连接
     */
    static public function createReturnUrl(Order $order){

    }

    /*
     * 异步通知商户
     */
    static public function notify(Order $order){

    }
}