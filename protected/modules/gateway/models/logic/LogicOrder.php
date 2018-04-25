<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\model\UserPaymentInfo;
use Yii;
use app\common\models\model\User;
use app\modules\gateway\models\model\Order;

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

        $orderData['merchant_account'] = $merchant->username;
        $channelInfo = $merchantPayment->paymentChannel;

        $orderData['channel_id'] = $channelInfo->channel_id;
        $orderData['channel_merchant_id'] = $channelInfo->merchant_id;

        $hasOrder = Order::findOne(['app_id'=>$orderData['app_id'],'merchant_order_no'=>$request['order_no']]);
        if($hasOrder){
            throw new InValidRequestException('请不要重复下单');
        }

        $newOrder = new Order();
        $newOrder->setAttributes($orderData,false);
        $newOrder->save();

        return $newOrder;
    }

    static public function generateOrderNo(){
        return 'P'.date('ymdHis').mt_rand(10000,99999);
    }
}