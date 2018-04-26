<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\UserPaymentInfo;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ObjectNoticeResult;
use Yii;
use app\common\models\model\User;
use app\common\models\model\Order;
use app\components\Macro;

class LogicOrder
{
    static public function addOrder(array $request, User $merchant, UserPaymentInfo $merchantPayment){

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
        $orderData['created_at'] = time();

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
//            || !$noticeResult->amount
        ){
            throw new InValidRequestException('支付结果对象错误',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
        }

        $order = $noticeResult->order;
        //已经支付
        if($order->status === Order::STATUS_PAID){
            //TODO: 订单成功，通知未成功，进行一次通知？
            if($order->notify_status !== Order::NOTICE_STATUS_SUCCESS){

            }

//            throw new InValidRequestException('订单已经处理，请不要重复刷新',Macro::ERR_PAYMENT_ALREADY_DONE);
            if($order->status === Order::STATUS_FAIL){
                self::paySuccess($order, $noticeResult->msg);
            }else{

                self::paySuccess($order,$noticeResult->amount,$noticeResult->channelOrderNo);

                self::bonus($order);

            }
        }

    }

    /*
     * 订单支付失败
     *
     * @param Order $order 订单对象
     * @param String $failMsg 失败描述信息
     */
    static public function payFail(Order $order, $failMsg='')
    {
        if ($order->status === Order::STATUS_FAIL) {
            return true;
        }

        $order->status = Order::STATUS_FAIL;
        $order->fail_msg = $failMsg;
        $order->save();
    }

    /*
     * 订单支付成功
     *
     * @param Order $order 订单对象
     * @param Decimal $paidAmount 实际支付金额
     * @param String $channelOrderNo 第三方流水号
     */
    static public function paySuccess(Order $order,$paidAmount,$channelOrderNo){

        if($order->status === Order::STATUS_PAID){
            return true;
        }

        //更改订单状态
        $order->paid_amount = $paidAmount;
        $order->channel_order_no = $channelOrderNo;
        $order->status = Order::STATUS_PAID;
        $order->paid_at = time();
        $order->save();

        $logicUser = new LogicUser($order->merchant);
        //更新充值金额
        bcscale(6);
        $logicUser->changeUserBalance($order->paid_amount, Financial::EVENT_TYPE_RECHARGE, $order->order_no, Yii::$app->request->userIP);

        //需扣除充值手续费
        $rechargeFee =  0-bcmul($order->merchant->recharge_rate,$order->paid_amount);
        $logicUser->changeUserBalance($rechargeFee, Financial::EVENT_TYPE_RECHARGE_FEE, $order->order_no, Yii::$app->request->userIP);

    }

    /*
     * 订单分红
     */
    static public function bonus(Order $order){
        if($order->financial_status === Order::FINANCIAL_STATUS_SUCCESS){
            return true;
        }

        //所有上级代理UID
        $parentIds = $order->merchant->getAllParentAgentId();
        //从自己开始算
        $parentIds[] = $order->merchant->id;

        foreach ($parentIds as $pid){
            $pUser = User::findActive($pid);
            if(!empty($pUser)){
                if(empty($pUser->recharge_parent_rebate_rate)){
                    Yii::info(["order bonus, recharge_parent_rebate_rate empty",$pUser->id,$pUser->username]);
                    continue;
                }
                //有上级的才返，余额操作对象是上级代理
                if($pUser->parentAgent){
                    $logicUser =  new LogicUser($pUser->parentAgent);
                    $rechargeFee =  bcmul($pUser->recharge_parent_rebate_rate,$order->paid_amount);
                    $logicUser->changeUserBalance($rechargeFee, Financial::EVENT_TYPE_BONUS, $order->order_no, Yii::$app->request->userIP);
                }

            }
        }

        //更新订单账户处理状态
        $order->financial_status = Order::FINANCIAL_STATUS_SUCCESS;
        $order->save();
    }

    static public function createNotifyParameters(Order $order){

        switch ($order->status){
            case Order::STATUS_PAID:
                $tradeStatus = 'success';
                break;
            case Order::STATUS_PAYING:
                $tradeStatus = 'paying';
                break;
            case Order::STATUS_FAIL:
                $tradeStatus = 'failed';
                break;
            default:
                $tradeStatus = 'failed';
        }

        $notifyType = 'back_notify';
        if (php_sapi_name() != "cli" && Yii::$app->request->isGet) {
            $notifyType = 'bank_page';
        }

        $arrParams = [
            'merchant_code'=>$order->merchant_id,
            'order_no'=>$order->merchant_order_no,
            'order_amount'=>$order->paid_amount,
            'order_time'=>date('Y-m-d H:i:s',$order->created_at),
            'return_params'=>$order->return_params,
            'trade_no'=>$order->order_no,
            'trade_time'=>date('Y-m-d H:i:s',$order->paid_at),
            'trade_status'=>$tradeStatus,
            'notify_type'=>$notifyType,//back_notify
        ];
        //'sign'=>$order['xxxx'],
        $signType = Yii::$app->params['paymentGateWayApiDefaultSignType'];
        $key = $order->merchant->paymentInfo->app_key_md5;
        $arrParams['sign'] = SignatureHelper::calcSign($arrParams, $key, $signType);

        return $arrParams;
    }

    /*
     * 生成订单同步通知跳转连接
     */
    static public function createReturnUrl(Order $order){

        $arrParams = self::createNotifyParameters($order);
        $url = $order->reutrn_url.'?'.http_build_query($arrParams);
        return $url;
    }

    /*
     * 异步通知商户
     */
    static public function notify(Order $order){

    }
}