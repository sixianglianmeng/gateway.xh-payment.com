<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicUser;
use app\common\models\model\AccountOpenFee;
use app\common\models\model\ChannelAccount;
use app\common\models\model\ChannelAccountRechargeMethod;
use app\common\models\model\Financial;
use app\common\models\model\LogApiRequest;
use app\common\models\model\MerchantRechargeMethod;
use app\common\models\model\Order;
use app\common\models\model\SiteConfig;
use app\common\models\model\User;
use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use app\components\Util;
use app\jobs\PaymentNotifyJob;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ChannelPayment;
use Yii;

class LogicOrder
{
    //通知失败后间隔通知时间
    const NOTICE_DELAY = 300;
    const RAND_REDIRECT_SECRET_KEY = "5c3865c23722f49247096d24c5de0e2a";

    /*
     * 添加充值记录
     *
     * @param array $request 请求数组
     * @param User $merchant 充值账户
     * @param MerchantRechargeMethod $paymentChannelAccount 充值的三方渠道账户
     */
    static public function addOrder(array $request, User $merchant, MerchantRechargeMethod $rechargeMethod)
    {

        $orderData                      = [];
        $orderData['app_id']              = $request['app_id'] ?? $merchant->id;
        $orderData['merchant_order_no'] = $request['order_no'];
        $hasOrder = Order::findOne(['app_id' => $orderData['app_id'], 'merchant_order_no' => $orderData['merchant_order_no']]);
        if ($hasOrder) {
              throw new OperationFailureException('请不要重复下单');
//            return $hasOrder;
        }

        $orderData['type']                 = $request['type']??1;
        $orderData['bak']                  = $request['bak']."\n"??'';
        $orderData['pay_method_code']      = $request['pay_type'];
        $orderData['amount']               = $request['order_amount'];
        $orderData['notify_url']           = $request['notify_url'] ?? '';
        $orderData['return_url']           = $request['return_url'] ?? '';
        $orderData['bank_code']            = $request['bank_code'] ?? '';
        $orderData['bank_code']            = strtoupper($orderData['bank_code']);
        $orderData['op_uid']               = $request['op_uid'] ?? 0;
        $orderData['op_username']          = $request['op_username'] ?? '';
        $orderData['merchant_user_id']     = $request['user_id'] ?? '';
        $orderData['merchant_order_time']  = $request['order_time'];
        $orderData['description']          = '';
        $orderData['notify_ret']           = '';
        $orderData['client_ip']            = $request['client_ip'] ?? Yii::$app->request->userIP;
        $orderData['return_params']        = $request['return_params'] ?? '';
        $orderData['status']               = Order::STATUS_NOTPAY;
        $orderData['financial_status']     = Order::FINANCIAL_STATUS_NONE;
        $orderData['notify_status']        = Order::NOTICE_STATUS_NONE;
        $orderData['created_at']           = time();
        $orderData['merchant_id']          = $merchant->id;
        $orderData['merchant_account']     = $merchant->username;
        $orderData['all_parent_agent_id']  = $merchant->all_parent_agent_id;
        $orderData['channel_id']           = $rechargeMethod->channel_id;
        $orderData['channel_account_id']   = $rechargeMethod->channel_account_id;
        $orderData['method_config_id']     = $rechargeMethod->id;
        $orderData['settlement_type']      = $rechargeMethod->settlement_type?$rechargeMethod->settlement_type:SiteConfig::cacheGetContent('default_settlement_type');
        $orderData['expect_settlement_at'] = MerchantRechargeMethod::getExpectSettlementTime($orderData['settlement_type']);
        $orderData['settlement_at']        = 0;
        $channelAccount                    = $rechargeMethod->channelAccount;
        if(empty($channelAccount)){
            throw new OperationFailureException("商户支付方式({$rechargeMethod->method_name})未配置渠道:{$rechargeMethod->channel_account_id}");
        }
        $orderData['channel_merchant_id']  = $channelAccount->merchant_id;
        $orderData['channel_app_id']       = $channelAccount->app_id;

        $orderData['fee_rate']   = $rechargeMethod->fee_rate;
        //防止费率填写错误，手续费不大于订单金额
        if($orderData['fee_rate']>1) $orderData['fee_rate']=1;
        $orderData['fee_amount'] = bcmul($rechargeMethod->fee_rate, $orderData['amount'], 6);
        $orderData['order_no']   = self::generateOrderNo($orderData);
        $channelAccountRechargeConfig = $rechargeMethod->getChannelAccountMethodConfig();
        $orderData['plat_fee_rate']       = $channelAccountRechargeConfig->fee_rate;

        //所有上级代理UID
        $parentConfigModels = $rechargeMethod->getMethodAllParentAgentConfig($rechargeMethod->method_id);
        //把自己也存进去
        $parentConfigModels[] = $rechargeMethod;
        $parentConfigs = [];
        foreach ($parentConfigModels as $pc){
            //跳过未设置费率或小于自身费率或小于渠道费率的上级
            if($pc->fee_rate <= 0
                || $pc->fee_rate < $orderData['plat_fee_rate']
                || $pc->fee_rate > $orderData['fee_rate']

            ){
                Yii::info(['parent fee is less',$orderData['order_no'],$orderData['merchant_account'],$pc->merchant_account,'plat_fee_rate',$orderData['plat_fee_rate'],'pc fee_rate ',$pc->fee_rate,'order fee',$orderData['fee_rate']]);
                continue;
            }
            $parentConfigs[] = [
                'config_id'=>$pc->id,
                'fee_rate'=>$pc->fee_rate,
                'parent_rebate_rate'=>$pc->parent_recharge_rebate_rate,
                'app_id'=>$pc->app_id,
                'merchant_id'=>$pc->merchant_id,
                'channel_account_id'=>$rechargeMethod->channel_account_id,
            ];
        }
        $orderData['plat_fee_amount']     = bcmul($orderData['plat_fee_rate'], $orderData['amount'], 6);
        $orderData['plat_fee_profit'] = 0;
        //如果上级列表不仅有自己
        if(count($parentConfigs)>1){
            $orderData['all_parent_recharge_config'] = json_encode($parentConfigs);
            //上级代理列表第一个为最上级代理
            $topestPrent = array_shift($parentConfigs);
            $orderData['plat_fee_profit']     = bcmul(bcsub($topestPrent['fee_rate'],$orderData['plat_fee_rate'],6), $orderData['amount'], 6);

            if($topestPrent['fee_rate']<$orderData['plat_fee_rate']){
                Yii::error("商户费率配置错误,小于渠道最低费率: 顶级商户ID:{$topestPrent['merchant_id']},商户渠道账户ID:{$topestPrent['channel_account_id']},商户费率:{$topestPrent['fee_rate']},渠道名:{$rechargeMethod->channel_account_name},渠道费率:{$orderData['plat_fee_rate']}");
                throw new InValidRequestException("商户费率配置错误,小于渠道最低费率!");
            }
        }
        //没有上级,平台利润为商户-渠道
        else{
            $orderData['plat_fee_profit']     = bcmul(bcsub($rechargeMethod->fee_rate,$orderData['plat_fee_rate'],6), $orderData['amount'], 6);
            $orderData['all_parent_recharge_config'] = json_encode([]);
        }

        unset($parentConfigs);
        unset($parentConfigModels);

        $newOrder = new Order();
        $newOrder->setAttributes($orderData, false);
        $newOrder->save();

        //接口日志埋点
        if(empty($orderData['op_uid']) && empty($orderData['op_username'])){
            Yii::$app->params['apiRequestLog'] = [
                'event_id'=>$newOrder->merchant_order_no,
                'event_type'=>LogApiRequest::EVENT_TYPE_IN_RECHARGE_ADD,
                'merchant_id'=>$order->merchant_id??$merchant->id,
                'merchant_name'=>$order->merchant_account??$merchant->username,
                'channel_account_id'=>$rechargeMethod->id,
                'channel_name'=>$rechargeMethod->channel_account_name,
            ];
        }

        try{
            self::beforeAddOrder($newOrder, $merchant, $rechargeMethod->channelAccount, $rechargeMethod, $channelAccountRechargeConfig);
        }catch (\Exception $e){
            throw new OperationFailureException($e->getMessage(),$e->getCode());
        }

        return $newOrder;
    }

    /*
     * 充值前置操作
     * 可进行额度校验的等操作
     *
     * @param array $request 请求数组
     * @param User $merchant 提款账户
     * @param ChannelAccount $paymentChannelAccount 提款的三方渠道账户
     * @param MerchantRechargeMethod $rechargeMethod 商户的当前收款渠道配置
     */
    static public function beforeAddOrder(Order &$order, User $merchant, ChannelAccount $paymentChannelAccount, MerchantRechargeMethod $rechargeMethod,
                                          ChannelAccountRechargeMethod $channelAccountRechargeMethod){
        $userPaymentConfig = $merchant->paymentInfo;
        //站点是否允许费率设置为0
        $feeCanBeZero = SiteConfig::cacheGetContent('recharge_fee_can_be_zero');

        //账户费率检测
        if(!$feeCanBeZero && $rechargeMethod->fee_rate <= 0){
            throw new OperationFailureException($order->order_no.' 费率不能设置为0',Macro::ERR_MERCHANT_FEE_CONFIG);
        }

        //账户支付方式开关检测
        if($rechargeMethod->status != MerchantRechargeMethod::STATUS_ACTIVE){
            throw new OperationFailureException($order->order_no.' 商户此支付方式通道开关未打开',Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        //检测账户单笔限额
        if($userPaymentConfig->recharge_quota_pertime && $order->amount > $userPaymentConfig->recharge_quota_pertime){
            throw new OperationFailureException($order->order_no.' 超过商户单笔限额:'.$userPaymentConfig->recharge_quota_pertime,Macro::ERR_PAYMENT_REACH_ACCOUNT_QUOTA_PER_TIME);
        }
        //检测账户日限额
        if($userPaymentConfig->recharge_quota_perday
            && (($userPaymentConfig->recharge_today+$order->amount) > $userPaymentConfig->recharge_quota_perday)
        ){
            throw new OperationFailureException($order->order_no." 超过商户日限额{$userPaymentConfig->recharge_quota_perday},当前为$userPaymentConfig->recharge_today",Macro::ERR_PAYMENT_REACH_ACCOUNT_QUOTA_PER_DAY);
        }
        //检测是否支持api充值
        if(empty($order->op_uid) && $userPaymentConfig->allow_api_recharge==UserPaymentInfo::ALLOW_API_RECHARGE_NO){
            throw new OperationFailureException($order->order_no.' 商户不支持API支付',Macro::ERR_PAYMENT_API_NOT_ALLOWED);
        }
        //检测是否支持手工充值
        elseif(!empty($order->op_uid) && $userPaymentConfig->allow_manual_recharge==UserPaymentInfo::ALLOW_MANUAL_RECHARGE_NO){
            throw new OperationFailureException(null,Macro::ERR_PAYMENT_MANUAL_NOT_ALLOWED);
        }

        //渠道费率检测
        if(!$feeCanBeZero && $channelAccountRechargeMethod->fee_rate <= 0){
            throw new OperationFailureException($order->order_no.' 费率不能设置为0',Macro::ERR_CHANNEL_FEE_CONFIG);
        }
        //检测渠道单笔最低限额
        if($paymentChannelAccount->min_recharge_pertime && $order->amount < $paymentChannelAccount->min_recharge_pertime){
            throw new OperationFailureException("单笔最低限额为:".bcadd(0,$paymentChannelAccount->min_remit_pertime,2));
        }
        //检测渠道单笔限额
        if($paymentChannelAccount->recharge_quota_pertime && $order->amount > $paymentChannelAccount->recharge_quota_pertime){
            throw new OperationFailureException(null,Macro::ERR_PAYMENT_REACH_CHANNEL_QUOTA_PER_TIME);
        }
        //检测渠道日限额
        if($paymentChannelAccount->recharge_quota_perday
            && (($paymentChannelAccount->recharge_today+$order->amount) > $paymentChannelAccount->recharge_quota_perday)
        ){
            throw new OperationFailureException(null,Macro::ERR_PAYMENT_REACH_CHANNEL_QUOTA_PER_DAY);
        }
    }

    static public function generateOrderNo($orderArr){
        $payType = str_pad($orderArr['pay_method_code'],2,'0',STR_PAD_LEFT);
        return '1'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function generateMerchantOrderNo(){
        return 'Msys'.date('ymdHis').mt_rand(1000,9999);
    }

    static public function getOrderByOrderNo($orderNo){
        $order = Order::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    static public function processChannelNotice($noticeResult){
        if(empty($noticeResult['data']['order'])){
            throw new InValidRequestException('支付回调:支付结果对象为空:'.$noticeResult['data']['order_no']??'',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
        }

        if(empty($noticeResult['data']['trade_status'])){
            throw new InValidRequestException('支付回调:trade_status为空:'.$noticeResult['data']['order_no']??'',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
        }

        if(!LogicChannelAccount::checkChannelIp($noticeResult['data']['order']->channel)){
            throw new OperationFailureException("支付回调: 服务器IP未在白名单中：".$noticeResult['data']['order_no']??'');
        }

        $order = $noticeResult['data']['order'];

        //接口日志埋点
        $eventType = LogApiRequest::EVENT_TYPE_IN_RECHARGE_RETURN;
        if( Yii::$app->request->method=='POST'){
            $eventType = LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY;
        }
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->order_no,
            'event_type'=>$eventType,
            'merchant_id'=>$order->merchant_id,
            'merchant_name'=>$order->merchant_account,
            'channel_account_id'=>$order->channel_merchant_id,
            'channel_name'=>$order->channelAccount->channel_name,
        ];


        if(empty($noticeResult['data']['amount'])){
            Yii::info('支付回调:回调金额为0:'.$noticeResult['data']['order_no']??'',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
            return $order;
        }

        //未处理
        if( $noticeResult['status'] === Macro::SUCCESS
            && $order->status !== Order::STATUS_PAID
            && $order->status !== Order::STATUS_SETTLEMENT
            && $noticeResult['data']['amount'] > 0
            && $noticeResult['data']['trade_status'] == Order::STATUS_PAID
            && bccomp($order->amount, $noticeResult['data']['amount'], 2)!==1
        ){
            self::paySuccess($order,$noticeResult['data']['amount'],$noticeResult['data']['channel_order_no']);

            if($order->notify_status != Order::NOTICE_STATUS_SUCCESS){
                self::notify($order);
            }
        }
        //订单状态成功但是金额小于订单金额
        elseif($noticeResult['status'] === Macro::SUCCESS
            && bccomp($order->amount, $noticeResult['data']['amount'], 2)===1
            && $noticeResult['data']['trade_status'] == Order::STATUS_PAID
        ){
            $order = self::payFail($order, "充值回调金额({$noticeResult['data']['amount']})与订单金额({$order->amount})不一致!");
        }
        //订单失败
        elseif( $noticeResult['status'] === Macro::SUCCESS
            && $noticeResult['data']['trade_status'] == Order::STATUS_FAIL
        ){
            $order = self::payFail($order,$noticeResult['msg']);
            Yii::info(__FUNCTION__.' order not paid: '.$noticeResult['data']['order_no']);
        }

        return $order;
    }

    /*
     * 订单支付失败
     *
     * @param Order $order 订单对象
     * @param String $failMsg 失败描述信息
     */
    static public function payFail(Order &$order, $failMsg='')
    {
        if ($order->status === Order::STATUS_FAIL) {
            return $order;
        }

        $order->status = Order::STATUS_FAIL;
        $order->fail_msg .= $failMsg."\n";
        $order->bak .= $failMsg."\n";
        $order->save();

        return $order;
    }

    /*
     * 订单支付成功
     *
     * @param Order $order 订单对象
     * @param Decimal $paidAmount 实际支付金额
     * @param String $channelOrderNo 第三方流水号
     */
    static public function paySuccess(Order &$order,$paidAmount,$channelOrderNo, $opUid=0, $opUsername='',$bak=''){
        Yii::info([__FUNCTION__.' '.$order->order_no.','.$paidAmount.','.$channelOrderNo]);
        if(
            $order->status != Order::STATUS_PAID
            && $order->status !== Order::STATUS_SETTLEMENT
        ){
            $db = Yii::$app->db;
            $transaction = $db->beginTransaction();
            try {

                //更改订单状态
                $order->paid_amount = $paidAmount;
                //实际付款金额小于于订单金额
                if(bccomp($order->amount, $order->paid_amount, 2)===1){
                    Yii::error("{$order->order_no} paid amount($order->paid_amount) is not equal origin amount($order->amount):".bccomp($order->amount, $order->paid_amount, 2));
                    return self::payFail($order,"付款金额{$order->paid_amount}小于订单金额{$order->amount}");
                }
                //实际金额大于订单金额
                elseif(bccomp($order->amount, $order->paid_amount, 2)===-1){
                    $bak.=date('Ymd H:i:s')." 更新订单金额：付款金额{$order->paid_amount}大于订单金额{$order->amount}\n";
                }
                Yii::info([$order->amount,$order->paid_amount,bccomp($order->amount, $order->paid_amount, 2)]);
                if($channelOrderNo && !$order->channel_order_no) $order->channel_order_no = $channelOrderNo;
                $order->status = Order::STATUS_PAID;
                $order->paid_at = time();
                $bak.=date('Ymd H:i:s');
                if($opUsername){
                    $bak.=" {$opUsername}设置为订单状态成功\n";
                }else{
                    $bak.="订单回调成功\n";
                }

                if($bak) $order->bak.=$bak;
                $order->save();

                //更新待结算金额
                $logicUser = new LogicUser($order->merchant);
                $logicUser->changeUserUnsettleBalance(bcsub($order->amount, $order->fee_amount, 6));

                //更新用户及渠道当天充值计数
                self::updateTodayQuota($order);

                //D0,T0结算，自动进行结算
                //或者系统启用自动结算
                $autoSettlemement = SiteConfig::cacheGetContent('recharge_auto_settlement');
                Yii::info([__FUNCTION__.' '.$order->order_no.',settlement ',$autoSettlemement,date('Ymd H:i:s',$order->settlement_at),$order->settlement_type]);
                if($autoSettlemement
                     &&
                    ($order->settlement_at<=time() && substr($order->settlement_type,1)=='0')
                ){
                    self::settlement($order);
                }

                //开户费订单处理
                Yii::info([__FUNCTION__.' '.$order->order_no.',AccountOpenFee ',$order->type]);
                if(isset($order->type) && $order->type == Order::TYPE_ACCOUNT_OPEN){
                    $accountOpenInfo = AccountOpenFee::findOne(['order_no'=>$order->order_no]);
                    Yii::info([__FUNCTION__.' '.$order->order_no.',AccountOpenFee user',$accountOpenInfo->username]);
                    if(!$accountOpenInfo){
                        Yii::error("未找到商户开户费订单对应用户:{$order->merchant_id}");
                    }else{
                        $accountOpenInfo->status = AccountOpenFee::STATUS_PAID;
                        $accountOpenInfo->paid_at = time();
                        $accountOpenInfo->fee_paid = $order->paid_amount;
                        $accountOpenInfo->save();
                        Yii::info([__FUNCTION__.' '.$order->order_no.',AccountOpenFee updated',$accountOpenInfo->user_id,$accountOpenInfo->status ]);
                        User::updateAll(['account_open_fee_status' => AccountOpenFee::STATUS_PAID], ['id', $accountOpenInfo->user_id]);
                    }
                }

                $transaction->commit();
                return true;
            } catch(\Exception $e) {
                $transaction->rollBack();
                throw $e;
                return false;
            } catch(\Throwable $e) {
                $transaction->rollBack();
                throw $e;
                return false;
            }
        }

        return $order;
    }


    /*
     * 订单结算
     *
     * @param Order $order 订单对象
     * @param String $bak 备注
     */
    static public function settlement(Order &$order, $opUid=0, $opUsername='', $bak='', $ip=''){
        Yii::info(__FUNCTION__.' '.$order->order_no.' '.$bak);
        if($order->status !== Order::STATUS_PAID){
            return $order;
        }

        $logicUser = new LogicUser($order->merchant);
        //更新充值金额
        bcscale(9);

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {

            $user = $logicUser->changeUserBalance($order->paid_amount, Financial::EVENT_TYPE_RECHARGE, $order->order_no, $order->amount, Yii::$app->request->userIP);
            //重新设置以更新用户余额属性，防止第一次充值时手续费判断不成功
            $logicUser = new LogicUser($user);
            //需扣除充值手续费
            $logicUser->changeUserBalance(0-$order->fee_amount, Financial::EVENT_TYPE_RECHARGE_FEE, $order->order_no, $order->amount,
                Yii::$app->request->userIP);

            //更改订单状态
            $order->status = Order::STATUS_SETTLEMENT;
            $order->settlement_at = time();
            if(empty($bak) && $opUsername) $bak=date('Ymd H:i:s')." {$opUsername} 设置为已结算\n";
            $order->bak .=$bak;
            $order->save();

            //发放分润
            $order = self::bonus($order);

            //更新待结算金额
            $logicUser = new LogicUser($order->merchant);
            $logicUser->changeUserUnsettleBalance(0-bcsub($order->amount,$order->fee_amount, 6));

            $transaction->commit();
            return true;
        } catch(\Exception $e) {
            $transaction->rollBack();
            throw $e;
            return false;
        } catch(\Throwable $e) {
            $transaction->rollBack();
            throw $e;
            return false;
        }

        return $order;
    }

    /*
     * 更新订单对应商户及通道的当日金额计数
     *
     * @param Order $order 订单对象
     */
    static public function updateTodayQuota(Order $order){
        $order->merchant->paymentInfo->updateCounters(['recharge_today' => $order->amount]);
        $order->channelAccount->updateCounters(['recharge_today' => $order->amount]);
    }

    /*
     * 冻结订单
     *
     * @param Order $order 订单对象
     * @param String $bak 备注
     */
    static public function frozen(Order &$order, $opUid, $opUsername, $bak='', $ip=''){
        Yii::info(__FUNCTION__.' '.$order->order_no.' '.$bak);
        if($order->status !== Order::STATUS_SETTLEMENT){
            throw new OperationFailureException('订单状态错误，已结算订单才能冻结:'.$order->status);
        }

        $logicUser = new LogicUser($order->merchant);
        if(!$ip) $ip = Yii::$app->request->userIP;
        //冻结余额
        $logicUser->changeUserFrozenBalance($order->amount, Financial::EVENT_TYPE_RECHARGE_FROZEN, $order->order_no, $order->amount, $ip, $bak, $opUid, $opUsername);

        //更改订单状态
        $order->status = Order::STATUS_FREEZE;
//        $order->op_uid = $opUid;
//        $order->op_username = $opUsername;
        //            $order->op_uid = $opUid;
        //            $order->op_username = $opUsername;
        if(empty($bak) && $opUsername) $bak="{$opUsername} set frozen at ".date('Ymd H:i:s')."\n";
        $order->bak .=$bak;
        $order->save();

        return $order;
    }

    /*
     * 解冻订单
     *
     * @param Order $order 订单对象
     */
    static public function unfrozen(Order &$order, $opUid, $opUsername, $bak='', $ip=''){
        Yii::info(__FUNCTION__.' '.$order->order_no.' '.$bak);
        if($order->status != Order::STATUS_FREEZE){
            return $order;
        }

        $logicUser = new LogicUser($order->merchant);
        //冻结余额
        if(!$ip) $ip = Yii::$app->request->userIP;
        $logicUser->changeUserFrozenBalance((0-$order->amount), Financial::EVENT_TYPE_RECHARGE_UNFROZEN,
            $order->order_no, $order->amount, $ip, $bak, $opUid, $opUsername);

        //更改订单状态
        $order->status = Order::STATUS_SETTLEMENT;
        if(empty($bak) && $opUsername) $bak="{$opUsername} set unfrozen at ".date('Ymd H:i:s')."\n";
        $order->bak .=$bak;
        $order->save();

        return $order;
    }

    /**
     * 订单退款
     *
     * @param Order $order 订单对象
     * @param string $reason 退款原因
     * @param string $ip 操作人员$ip
     * @param int $opUid 操作人员uid
     * @param string $opUsername 操作人员用户名
     * @return mixed
     * @throws OperationFailureException
     * @throws \Throwable
     */
    static public function refund(Order &$order, string $reason, string $ip='', int $opUid=0, string $opUsername=''){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $order->order_no);
        if(
            $order->status == order::STATUS_SETTLEMENT
        ){
            //退回账户扣款
            $logicUser = new LogicUser($order->merchant);
            $amount =  $order->amount;
            $logicUser->changeUserBalance((0-$amount), Financial::EVENT_TYPE_RECHARGE_REFUND, $order->order_no, $order->amount,$ip);

            //退回手续费给用户
            //手续费不退
//            $logicUser->changeUserBalance($order->order_fee, Financial::EVENT_TYPE_RECHARGE_REFUND, $order->order_no, $order->amount, $ip, $reason, $opUid, $opUsername);

            //扣除帐户分润
            //分润不退
//            $parentRebate = Financial::findAll(['event_id'=>$order->id,'event_type'=>Financial::EVENT_TYPE_RECHARGE_BONUS,'status'=>Financial::STATUS_FINISHED]);
//            foreach ($parentRebate as $pr){
//                $logicUser->changeUserBalance((0-$pr->amount), Financial::EVENT_TYPE_RECHARGE_BONUS_REFUND,$order->order_no, $order->amount, $ip, $reason, $opUid, $opUsername);
//            }

            $order->status = order::STATUS_REFUND;
            $order->bak.=date('Ymd H:i:s')." 订单退款:{$reason}\n";
            $order->save();

            return $order;
        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$order->order_no,"订单状态错误，无法退款:".$order->status]);
            throw new OperationFailureException('订单状态错误，无法退款:'.$order->status);
        }
    }

    /*
     * 订单分红
     */
    static public function bonus(Order &$order){
        Yii::info([__CLASS__.':'.__FUNCTION__.' '.$order->order_no]);
        if($order->financial_status === Order::FINANCIAL_STATUS_SUCCESS){
            Yii::warning([__FUNCTION__.' order has been bonus,will return, '.$order->order_no]);
            return $order;
        }

        //直接取保存在订单表的支付配置快照
        $parentRechargeConfig = $order->getAllParentRechargeConfig();
        $parentRechargeConfigMaxIdx = count($parentRechargeConfig)-1;
        for($i=$parentRechargeConfigMaxIdx; $i>=0; $i--){
            $rechargeConfig = $parentRechargeConfig[$i];

            Yii::info(["order bonus, find config",json_encode($rechargeConfig)]);
            //parent_recharge_rebate_rate
            if ($rechargeConfig['parent_rebate_rate']<=0) {
                Yii::info(["order bonus, parent_rebate_rate empty",$order->order_no]);
                continue;
            }

            $pUser = User::findActive($rechargeConfig['merchant_id']);
            //没有上级可以直接中断了
            if(!$pUser->parentAgent){
                Yii::info(["order bonus, has no parent",$pUser->id,$pUser->username]);
                break;
            }
            //有上级的才返，余额操作对象是上级代理
            Yii::info(["order bonus parent",$pUser->id,$pUser->username,$pUser->parentAgent->id,$pUser->parentAgent->username]);
            $logicUser =  new LogicUser($pUser->parentAgent);
            $rechargeFee =  bcmul($rechargeConfig['parent_rebate_rate'],$order->paid_amount);
            $logicUser->changeUserBalance($rechargeFee, Financial::EVENT_TYPE_RECHARGE_BONUS, $order->order_no, $order->amount, Yii::$app->request->userIP);
        }

        //更新订单账户处理状态
        $order->financial_status = Order::FINANCIAL_STATUS_SUCCESS;
        $order->save();

        return $order;
    }

    /*
     * 生成通知参数
     */
    static public function createNotifyParameters(Order $order){

        switch ($order->status){
            case Order::STATUS_PAID:
            case Order::STATUS_SETTLEMENT:
                $tradeStatus = 'success';
                break;
            case Order::STATUS_NOTPAY:
                $tradeStatus = 'paying';
                break;
            case Order::STATUS_FAIL:
                $tradeStatus = 'failed';
                break;
            default:
                $tradeStatus = 'failed';
                break;
        }

        $notifyType = 'back_notify';
        if (php_sapi_name() != "cli" && Yii::$app->request->isGet) {
            $notifyType = 'bank_page';
        }

        $arrParams = [
            'merchant_code'=>$order->merchant_id,
            'order_no'=>$order->merchant_order_no,
            'order_amount'=>bcadd($order->amount,0,2),
            'paid_amount'=>bcadd($order->paid_amount,0,2),
            'order_time'=>$order->created_at,
            'return_params'=>$order->return_params,
            'trade_no'=>$order->order_no,
            'trade_time'=>$order->paid_at,
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
        if(empty($order->reutrn_url)){
            return '';
        }

        $arrParams = self::createNotifyParameters($order);
        $url = $order->reutrn_url.'?'.http_build_query($arrParams);
        return $url;
    }

    /*
     * 更新通通知结果
     */
    static public function updateNotifyResult($orderNo, $retCode, $retContent){
        $order = self::getOrderByOrderNo($orderNo);
        if(!$order){
            throw new OperationFailureException("updateNotifyResult 订单不存在：{$orderNo}");
        }

        $order->notify_at = time();
        $order->notify_status = $retCode;
        $order->notify_ret = $retContent;
        $order->next_notify_time = time()+self::NOTICE_DELAY;
        $order->save();
        $order->updateCounters(['notify_times' => 1]);

        return $order;
    }

    /*
     * 异步通知商户
     */
    static public function notify(Order &$order){
        Yii::trace((new \ReflectionClass(__CLASS__))->getShortName().'-'.__FUNCTION__.' '.$order->order_no);
        if(!$order->notify_url
            || !in_array($order->status,[Order::STATUS_PAID,Order::STATUS_SETTLEMENT])
        ){
            return true;
        }

        $arrParams = self::createNotifyParameters($order);
        $job = new PaymentNotifyJob([
            'orderNo'=>$order->order_no,
            'url' => $order->notify_url,
            'data' => $arrParams,
        ]);
        Yii::$app->paymentNotifyQueue->push($job);//->delay(10)
    }

    /**
     * 获取订单状态
     *
     * @param string $orderNo 平台订单号
     * @param string $merchantOrderNo 商户订单号
     * @param User   $merchant 商户User对象
     *
     * @return Order|null
     * @throws OperationFailureException
     */
    public static function getStatus(string $orderNo = '', string $merchantOrderNo = '', User $merchant)
    {
        if($merchantOrderNo && !$orderNo){
            $order = Order::findOne(['merchant_order_no'=>$merchantOrderNo,'merchant_id'=>$merchant->id]);
        }
        elseif($orderNo){
            $order = Order::findOne(['order_no'=>$merchantOrderNo]);
        }else{
            throw new OperationFailureException("参数错误,平台订单号及商户订单号不能都为空.");
        }

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->merchant_order_no,
            'event_id'=>$merchantOrderNo?$merchantOrderNo:$orderNo,
            'event_type'=>LogApiRequest::EVENT_TYPE_IN_RECHARGE_QUERY,
            'merchant_id'=>$merchant->id,
            'merchant_name'=>$merchant->username,
            'channel_account_id'=>$order?$order->channel_merchant_id:0,
            'channel_name'=>$order?$order->channelAccount->channel_name:'',
        ];

        if(!$order){
            throw new OperationFailureException("订单不存在('platform_order_no:{$orderNo}','merchant_order_no:{$merchantOrderNo}')");
        }

        return $order;
    }

    /*
     * 到第三方查询订单状态
     */
    static public function queryChannelOrderStatus(Order &$order){
        Yii::info([(new \ReflectionClass(__CLASS__))->getShortName().':'.__FUNCTION__,$order->order_no]);

        $paymentChannelAccount = $order->channelAccount;

        $payment = new ChannelPayment($order, $paymentChannelAccount);
        //RECHARGE_QUERY_RESULT
        $ret = $payment->orderStatus();

        Yii::info('order status check: '.json_encode($ret,JSON_UNESCAPED_UNICODE));
        if($ret['status'] === 0){

            if(!empty($ret['data']['channel_order_no']) && empty($order->channel_order_no)){
                $order->channel_order_no = $ret['data']['channel_order_no'];
            }

            switch ($ret['data']['trade_status']){
                case Order::STATUS_PAID:
                case Order::STATUS_SETTLEMENT:

                    if($ret['data']['amount']>0){
                        $order->status = Order::STATUS_PAID;
                        $order = self::paySuccess( $order,$ret['data']['amount'],$ret['data']['channel_order_no']);
                    }

                    break;
                case Order::STATUS_FAIL:
                    $msg = $ret['message']?$ret['message']:'订单查询返回订单失败';
                    self::payFail($order,$msg);
                    break;
            }
        }
        //失败
        else{

        }

        return $order;
    }

    /*
     * 更新订单的客户端信息,如Ip
     */
    public static function updateClientInfo(&$order)
    {
        $order->client_ip          = Util::getClientIp();

        $clientId = PaymentRequest::getClientId();
        if ($clientId){
            $order->client_id = $clientId;
        }

        $order->save();
    }

    /**
     * 生成订单随机跳转地址
     *
     * @param $orderNo
     * @param int $leftRedirectTimes
     * @return string
     */
    public static function generateRandRedirectUrl($orderNo, $leftRedirectTimes = 1)
    {
        $data          = [
            'orderNo'           => $orderNo,
            'leftRedirectTimes' => $leftRedirectTimes,
        ];
        $encryptedData = Yii::$app->getSecurity()->encryptByPassword(json_encode($data), self::RAND_REDIRECT_SECRET_KEY);
        $encryptedData = urlencode(base64_encode($encryptedData));

        $baseUri = SiteConfig::cacheGetContent('payment_web_base_uri');
        $baseUri = $baseUri?$baseUri:Yii::$app->request->hostInfo;
        return $baseUri . "/order/go/{$encryptedData}.html";//'/order/go.html?sign=' . $encryptedData;
    }

    /**
     * 获取收银台地址
     *
     * @param $orderNo
     * @return string
     */
    public static function getCashierUrl($orderNo)
    {
        $baseUri = SiteConfig::cacheGetContent('payment_web_base_uri');
        $baseUri = $baseUri?$baseUri:Yii::$app->request->hostInfo;
        return $baseUri . '/order/pay.html?orderNo=' . $orderNo;
    }
}