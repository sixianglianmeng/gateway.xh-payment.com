<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\LogApiRequest;
use app\common\models\model\SiteConfig;
use app\common\models\model\UserPaymentInfo;
use app\components\Util;
use app\jobs\PaymentNotifyJob;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ChannelPayment;
use app\lib\payment\channels\BasePayment;
use app\lib\payment\ObjectNoticeResult;
use app\common\models\model\Remit;
use power\yii2\exceptions\ParameterValidationExpandException;
use Yii;
use app\common\models\model\User;
use app\components\Macro;
use app\common\exceptions\OperationFailureException;

class LogicRemit
{
    //通知失败后间隔通知时间
    const NOTICE_DELAY = 300;
    const REDIS_CACHE_KEY = 'lt_remit';

    /*
     * 添加提款记录
     *
     * @param array $request 请求数组
     * @param User $merchant 提款账户
     * @param ChannelAccount $paymentChannelAccount 提款的三方渠道账户
     * @param boolean $skipCheck 是否跳过生成订单前检测,用于批量提交出款订单时保证都入库
     */
    static public function addRemit(array $request, User $merchant, ChannelAccount $paymentChannelAccount, $skipCheck=false)
    {
        $remitData                      = [];
        $remitData['app_id']              = $request['app_id'] ?? $merchant->id;
        $remitData['merchant_order_no'] = $request['trade_no'];

        $hasRemit = Remit::findOne(['app_id' => $remitData['app_id'], 'merchant_order_no'=>$request['trade_no']]);
        if($hasRemit){
            throw new OperationFailureException('请不要重复下单');
            return $hasRemit;
        }

        $remitData['amount']            = $request['order_amount'];
        $remitData['bat_order_no']      = $request['bat_order_no'] ?? '';
        $remitData['bat_index']         = $request['bat_index'] ?? 0;
        $remitData['bat_count']         = $request['bat_count'] ?? 0;
        $remitData['bank_code']         = $request['bank_code'];
        $remitData['bank_account']      = $request['account_name'];
        $remitData['bank_no']           = $request['account_number'];
        $remitData['client_ip']   = $request['client_ip'] ?? '';
        $remitData['op_uid']      = $request['op_uid'] ?? 0;
        $remitData['op_username'] = $request['op_username'] ?? '';

        $remitData['status']    = Remit::STATUS_NONE;
        $remitData['remit_fee'] = $merchant->paymentInfo->remit_fee;
        if ($merchant->paymentInfo->allow_api_fast_remit == UserPaymentInfo::ALLOW_API_FAST_REMIT_YES) {
            $remitData['status'] = Remit::STATUS_CHECKED;
        }
        $remitData['bank_status']      = Remit::BANK_STATUS_NONE;
        $remitData['financial_status'] = Remit::FINANCIAL_STATUS_NONE;

        $remitData['merchant_id']         = $merchant->id;
        $remitData['merchant_account']    = $merchant->username;
        $remitData['all_parent_agent_id'] = $merchant->all_parent_agent_id;

        $remitData['channel_account_id']  = $paymentChannelAccount->id;
        $remitData['channel_id']          = $paymentChannelAccount->channel_id;
        $remitData['channel_merchant_id'] = $paymentChannelAccount->merchant_id;
        $remitData['channel_app_id']      = $paymentChannelAccount->app_id;
        $remitData['created_at']          = time();
        $remitData['order_no']          = self::generateRemitNo($remitData);

        $parentConfigModels = UserPaymentInfo::findAll(['app_id'=>$merchant->getAllParentAgentId()]);
        //把自己也存进去
        $parentConfigModels[] = $merchant->paymentInfo;
        $parentConfigs = [];
        foreach ($parentConfigModels as $pc){
            $parentConfigs[] = [
                'channel_account_id'=>$pc->remit_channel_account_id,
                'fee'=>$pc->remit_fee,
                'fee_rebate'=>$pc->remit_fee_rebate,
                'app_id'=>$pc->app_id,
                'merchant_id'=>$pc->user_id,
            ];
        }
        $remitData['all_parent_remit_config'] = json_encode($parentConfigs);

        $remitData['plat_fee_amount']     = $paymentChannelAccount->remit_fee;
        $remitData['plat_fee_profit']     = 0;//bcsub($topestPrent['fee'], $remitData['plat_fee_amount'],6);
        //如果上级列表不仅有自己
        if(count($parentConfigs)>1){
            $remitData['all_parent_recharge_config'] = json_encode($parentConfigs);
            //上级代理列表第一个为最上级代理
            $topestPrent = array_shift($parentConfigs);
            $remitData['plat_fee_profit']     = bcsub($topestPrent['fee'],$remitData['plat_fee_amount'],6);

            if($topestPrent['fee']<$remitData['plat_fee_amount']){
                Yii::error("商户费率配置错误,小于渠道最低费率: 顶级商户ID:{$topestPrent['merchant_id']},商户渠道账户ID:{$topestPrent['channel_account_id']},商户费率:{$topestPrent['fee']},渠道名:{$paymentChannelAccount->channel_name},渠道费率:{$remitData['plat_fee_amount']}");
                throw new InValidRequestException("商户费率配置错误,小于渠道最低费率!");
            }
        }
        //没有上级,平台利润为商户-渠道
        else{
            $remitData['plat_fee_profit']     = bcsub($remitData['remit_fee'],$remitData['plat_fee_amount'],6);

        }

        unset($parentConfigs);
        unset($parentConfigModels);

        $newRemit = new Remit();
        $newRemit->setAttributes($remitData,false);
        if(!$skipCheck){
            self::beforeAddRemit($newRemit, $merchant, $paymentChannelAccount);
        }

        $newRemit->save();

        if(empty($remitData['op_uid']) && empty($remitData['op_username'])){
            //接口日志埋点
            Yii::$app->params['apiRequestLog'] = [
                'event_id'=>$newRemit->order_no,
                'event_type'=> LogApiRequest::EVENT_TYPE_IN_REMIT_ADD,
                'merchant_id'=>$newRemit->merchant_id??$merchant->id,
                'merchant_name'=>$newRemit->merchant_account??$merchant->username,
                'channel_account_id'=>$paymentChannelAccount->id,
                'channel_name'=>$paymentChannelAccount->channel_name,
            ];
        }

        self::updateToRedis($newRemit);

        return $newRemit;
    }

    /*
     * 提款前置操作
     * 可进行额度校验的等操作
     *
     * @param array $request 请求数组
     * @param User $merchant 提款账户
     * @param ChannelAccount $paymentChannelAccount 提款的三方渠道账户
     */
    static public function beforeAddRemit(Remit $remit, User $merchant, ChannelAccount $paymentChannelAccount){
        $userPaymentConfig = $merchant->paymentInfo;
        //站点是否允许费率设置为0
        $feeCanBeZero = SiteConfig::cacheGetContent('remit_fee_can_be_zero');

        //账户费率检测
        if(!$feeCanBeZero && $userPaymentConfig->remit_fee <= 0){
            throw new OperationFailureException("用户出款费率不能设置为0:".Macro::ERR_MERCHANT_FEE_CONFIG);
        }

        //检测账户单笔限额
        if($userPaymentConfig->remit_quota_pertime && $remit->amount > $userPaymentConfig->remit_quota_pertime){
            throw new OperationFailureException('超过账户单笔限额:'.$userPaymentConfig->remit_quota_pertime,Macro::ERR_REMIT_REACH_ACCOUNT_QUOTA_PER_TIME);
        }
        //检测账户日限额
        if($userPaymentConfig->remit_quota_perday && $remit->remit_today > $userPaymentConfig->remit_quota_perday){
            throw new OperationFailureException('超过账户日限额:'.$userPaymentConfig->remit_quota_perday.',当前已使用:'.$remit->remit_today,Macro::ERR_REMIT_REACH_ACCOUNT_QUOTA_PER_DAY);
        }
        //检测是否支持api出款
        if(empty($remit->op_uid) && $userPaymentConfig->allow_api_remit==UserPaymentInfo::ALLOW_API_REMIT_NO){
            throw new OperationFailureException(null,Macro::ERR_PAYMENT_API_NOT_ALLOWED);
        }
        //检测是否支持手工出款
        elseif(!empty($remit->op_uid) && $userPaymentConfig->allow_manual_remit==UserPaymentInfo::ALLOW_MANUAL_REMIT_NO){
            throw new OperationFailureException(null.$userPaymentConfig->remit_quota_pertime,Macro::ERR_PAYMENT_MANUAL_NOT_ALLOWED);
        }

        //渠道费率检测
        if(!$feeCanBeZero && $paymentChannelAccount->remit_fee <= 0){
            throw new OperationFailureException("通道出款费率不能设置为0:".Macro::ERR_CHANNEL_FEE_CONFIG);
        }
        //检测渠道单笔限额
        if($paymentChannelAccount->remit_quota_pertime && $remit->amount > $paymentChannelAccount->remit_quota_pertime){
            throw new OperationFailureException('超过渠道单笔限额:'.$paymentChannelAccount->remit_quota_pertime,Macro::ERR_REMIT_REACH_CHANNEL_QUOTA_PER_TIME);
        }
        //检测渠道日限额
        if($paymentChannelAccount->remit_quota_perday && $paymentChannelAccount->remit_today > $paymentChannelAccount->remit_quota_perday){
            throw new OperationFailureException('超过渠道日限额:'.$paymentChannelAccount->remit_quota_perday.',当前已使用:'.$paymentChannelAccount->remit_today,Macro::ERR_REMIT_REACH_CHANNEL_QUOTA_PER_DAY);
        }
    }

    static public function processRemit($remit, ChannelAccount $paymentChannelAccount){
        Yii::info([__CLASS__.':'.__FUNCTION__,$remit->order_no,$remit->status]);
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
            case Remit::STATUS_NOT_REFUND:
                //$remit = self::refund($remit);
//                $remit->status = Remit::STATUS_NOT_REFUND;
//                $remit->save();
                break;
            default:
                break;
        }

        return $remit;
    }

    /*
     * 订单分润
     */
    static public function bonus(Remit $remit)
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);
        if ($remit->financial_status === Remit::FINANCIAL_STATUS_SUCCESS) {
            Yii::warning(__FUNCTION__ . ' remit has been bonus,will return, ' . $remit->order_no);
            return $remit;
        }

        //所有上级代理UID
        $parentIds = $remit->merchant->getAllParentAgentId();
        //从自己开始算
        $parentIds[] = $remit->merchant->id;

        bcscale(9);
        $parentRemitConfig = $remit->getAllParentRemitConfig();
        $parentRemitConfigMaxIdx = count($parentRemitConfig)-1;
        for($i=$parentRemitConfigMaxIdx; $i>=0; $i--){
            $remitConfig = $parentRemitConfig[$i];
            Yii::info(["remit bonus, find config",json_encode($remitConfig)]);

            $pUser      = User::findActive($remitConfig['merchant_id']);

            //有上级的才返
            if ($remitConfig['fee_rebate']<=0) {
                Yii::info(["remit bonus, parent fee empty", $pUser->id, $pUser->username,$remitConfig['fee_rebate']]);
                continue;
            }

            //没有上级可以直接中断了
            if (!$pUser->parentAgent) {
                Yii::info(["remit bonus, has no parent", $pUser->id, $pUser->username]);
                break;
            }

            //有上级的才返，余额操作对象是上级代理
            Yii::info(["remit bonus parent", $pUser->id, $pUser->username, $remitConfig['fee_rebate'],$pUser->parentAgent->id, $pUser->parentAgent->username]);
            $logicUser   = new LogicUser($pUser->parentAgent);
            $logicUser->changeUserBalance($remitConfig['fee_rebate'], Financial::EVENT_TYPE_REMIT_BONUS, $remit->order_no, $remit->amount,
                Yii::$app->request->userIP??'');
        }

        //更新订单账户处理状态
        $remit->financial_status = Remit::FINANCIAL_STATUS_SUCCESS;
        $remit->save();

        return $remit;
    }

    /*
     * 提款扣款
     */
    static public function deduct(Remit $remit){
        Yii::info(__CLASS__.':'.__FUNCTION__.' '.$remit->order_no);
        //账户余额扣款
        if($remit->status == Remit::STATUS_CHECKED){
            //账户扣款
            $logicUser = new LogicUser($remit->merchant);
            $amount =  0-$remit->amount;
            $ip = Yii::$app->request->userIP??'';
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REMIT, $remit->order_no, $remit->amount, $ip);
            //手续费
            $amount =  0-$remit->remit_fee;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REMIT_FEE, $remit->order_no, $remit->amount, $ip);

            //出款分润
            self::bonus($remit);

            $remit->status = Remit::STATUS_DEDUCT;
            $remit->save();

            return $remit;
        }else{
            throw new \app\common\exceptions\OperationFailureException('订单未审核，无法扣款 '.$remit->order_no);
        }
    }

    /*
     * 提交提款请求到银行
     */
    static public function commitToBank(Remit $remit, ChannelAccount $paymentChannelAccount){
        Yii::info(__CLASS__.':'.__FUNCTION__.' '.$remit->order_no);
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_REMIT_ADD,
            'merchant_id'=>$remit->channel_merchant_id,
            'merchant_name'=>$remit->channelAccount->merchant_account,
            'channel_account_id'=>$remit->channel_account_id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];

        if($remit->status == Remit::STATUS_CHECKED){
            $remit = self::deduct($remit);
        }

        if($remit->status == Remit::STATUS_DEDUCT){
            //提交到银行
            //银行状态说明：00处理中，04成功，05失败或拒绝
            $payment = new ChannelPayment($remit, $paymentChannelAccount);
            $ret = $payment->remit();

            Yii::info('remit commitToBank ret: '.$remit->order_no.' '.json_encode($ret,JSON_UNESCAPED_UNICODE));
            if($ret['status'] === Macro::SUCCESS){
                switch ($ret['data']['bank_status']){
                    case Remit::BANK_STATUS_PROCESSING:
                        $remit->status = Remit::STATUS_BANK_PROCESSING;
                        $remit->bank_status =  Remit::BANK_STATUS_PROCESSING;
                        break;
                    case Remit::BANK_STATUS_SUCCESS:
                        $remit->status = Remit::STATUS_SUCCESS;
                        $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
                        $remit->remit_at =  time();
                        break;
                    case  Remit::BANK_STATUS_FAIL:
                        $remit->status = Remit::STATUS_NOT_REFUND;
                        $remit->bank_status =  Remit::BANK_STATUS_FAIL;
                        break;
                    default:
                        throw new OperationFailureException('错误的银行返回值:'.$remit->order_no.' '.$ret['data']['bank_status']);
                        break;
                }

                if(!empty($ret['data']['channel_order_no']) && empty($remit->channel_order_no)){
                    $remit->channel_order_no = $ret['data']['channel_order_no'];
                }

                $remit->save();

                return $remit;
            }
            //提交失败暂不处理,等待重新提交
            else{
                if($ret['message']){
                    $remit->bank_ret = date('Y-m-d H:i:s').''.$ret['message']."\n";
                    $remit->save();
                }
            }


        }else{
            Yii::error(__CLASS__.':'.__FUNCTION__.' '.$remit->order_no." 订单状态错误，无法提交到银行:".$remit->status);
            throw new \app\common\exceptions\OperationFailureException('订单状态错误，无法提交到银行');
        }
    }

    static public function queryChannelRemitStatus(Remit $remit){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_REMIT_QUERY,
            'merchant_id'=>$remit->channel_merchant_id,
            'merchant_name'=>$remit->channelAccount->channel_name,
            'channel_account_id'=>$remit->channel_account_id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];
        $paymentChannelAccount = $remit->channelAccount;
        //提交到银行
        //银行状态说明：00处理中，04成功，05失败或拒绝
        $payment = new ChannelPayment($remit, $paymentChannelAccount);
        $ret = $payment->remitStatus();

        Yii::info('remit status check: '.json_encode($ret,JSON_UNESCAPED_UNICODE));
        if($ret['status'] === Macro::SUCCESS){
            switch ($ret['data']['bank_status']){
                case Remit::BANK_STATUS_PROCESSING:
                    $remit->status = Remit::STATUS_BANK_PROCESSING;
                    $remit->bank_status =  Remit::BANK_STATUS_PROCESSING;
                    break;
                case Remit::BANK_STATUS_SUCCESS:
                    $remit->status = Remit::STATUS_SUCCESS;
                    $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
                    $remit->remit_at =  time();
                    break;
                case  Remit::BANK_STATUS_FAIL:
                    $remit->status = Remit::STATUS_NOT_REFUND;
                    $remit->bank_status =  Remit::BANK_STATUS_FAIL;
                    if($ret['message']) $remit->bank_ret = date('Y-m-d H:i:s').''.$ret['message']."\n";
                    break;
            }

            if(!empty($ret['data']['channel_order_no']) && empty($remit->channel_order_no)){
                $remit->channel_order_no = $ret['data']['channel_order_no'];
            }

            $remit->save();

            if($remit->bank_status == Remit::BANK_STATUS_SUCCESS){
                self::afterSuccess($remit);
            }

            self::updateToRedis($remit);
        }
        //查询执行失败暂不处理,等待下一次查询
        else{

        }


        return $remit;
    }

    /**
     * 根据订单查询结果对订单做相应处理
     *
     */
    static public function processRemitQueryStatus($remitRet){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . json_encode($remitRet));

        if(
            !isset($remitRet['data']['bank_status'])
            || empty($remitRet['data']['remit'])
        ){

            if($remitRet['status'] === Macro::SUCCESS){
                switch ($remitRet['data']['bank_status']){
                    case Remit::BANK_STATUS_PROCESSING:
                        $remitRet['data']['remit']->status = Remit::STATUS_BANK_PROCESSING;
                        $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_PROCESSING;
                        break;
                    case Remit::BANK_STATUS_SUCCESS:
                        $remitRet['data']['remit']->status = Remit::STATUS_SUCCESS;
                        $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_SUCCESS;
                        $remitRet['data']['remit']->remit_at =  time();
                        break;
                    case  Remit::BANK_STATUS_FAIL:
                        $remitRet['data']['remit']->status = Remit::STATUS_NOT_REFUND;
                        $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_FAIL;
                        if($remitRet['message']) $remitRet['data']['remit']->bank_ret = date('Y-m-d H:i:s').''.$ret['message']."\n";
                        break;
                }

                if(!empty($ret['data']['channel_order_no']) && empty($remitRet['data']['remit']->channel_order_no)){
                    $remitRet['data']['remit']->channel_order_no = $ret['data']['channel_order_no'];
                }

                $remitRet['data']['remit']->save();

                if($remitRet['data']['remit']->bank_status == Remit::BANK_STATUS_SUCCESS){
                    self::afterSuccess($remitRet['data']['remit']);
                }

                self::updateToRedis($remitRet['data']['remit']);
            }
        }else{
            Yii::warning(__CLASS__ . ':' . __FUNCTION__ . ' error ret:' . json_encode($remitRet));
        }
    }

    static public function refund($remit, $reason = ''){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);
        if(
            $remit->status == Remit::STATUS_BANK_PROCESS_FAIL
            || $remit->status == Remit::STATUS_BANK_NET_FAIL
            || $remit->status == Remit::STATUS_NOT_REFUND
        ){
            //退回账户扣款
            $logicUser = new LogicUser($remit->merchant);
            $amount =  $remit->amount;
            $ip = Yii::$app->request->userIP??'';
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REFUND_REMIT, $remit->order_no, $remit->amount,$ip);
            //退回手续费
            $amount =  $remit->remit_fee;

            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REFUND_REMIT_FEE, $remit->order_no, $remit->amount, $ip);

            //退回分润
            $parentRebate = Financial::findAll(['event_id'=>$remit->id,
                'event_type'=>Financial::EVENT_TYPE_REMIT_BONUS,'uid'=>$remit->merchant_id]);
            foreach ($parentRebate as $pr){
                $logicUser->changeUserBalance((0-$remit->amount), Financial::EVENT_TYPE_REFUND_REMIT_BONUS,$remit->order_no, $remit->amount, $ip, $reason);
            }

            $remit->status = Remit::STATUS_REFUND;
            $remit->save();

            return $remit;
        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$remit->order_no,"订单状态错误，无法退款:".$remit->status]);
            throw new \app\common\exceptions\OperationFailureException('订单状态错误，无法退款:'.$remit->status);
        }
    }

    static public function generateRemitNo($remitData){
        return '2'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function generateMerchantRemitNo(){
        return 'Rsys'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function generateBatRemitNo(){
        return 'RB'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function getRemitByRemitNo($orderNo){
        $order = Remit::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    /*
     * 订单成功
     *
     * @param Remit $remit 订单对象
     */
    public static function setSuccess(Remit $remit, $opUid=0, $opUsername='',$bak='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        $remit->status = Remit::STATUS_SUCCESS;
        $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
        $remit->remit_at =  time();
        if($opUsername) $bak.="{$opUsername} set success at ".date('Ymd H:i:s')."\n";
        $remit->bak .=$bak;
        $remit->save();

        self::afterSuccess($remit);

        self::updateToRedis($remit);

        return $remit;
    }

    /*
     * 订单成功后续处理事件
     *
     * @param Remit $remit 订单对象
     */
    public static function afterSuccess(Remit $remit)
    {
        //更新用户及渠道当天充值计数
        self::updateTodayQuota($remit);

    }


    /*
     * 更新订单对应商户及通道的当日金额计数
     *
     * @param Remit $remit 订单对象
     */
    static public function updateTodayQuota(Remit $remit){
        $remit->merchant->paymentInfo->updateCounters(['remit_today' => $remit->amount]);
        $remit->channelAccount->updateCounters(['remit_today' => $remit->amount]);
    }

    /*
     * 订单失败
     *
     * @param Remit $remit 订单对象
     * @param String $failMsg 失败描述信息
     */
    public static function setFail(Remit $remit, $failMsg='', $opUid=0, $opUsername='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        if($failMsg) $remit->fail_msg = $remit->fail_msg.$failMsg.date('Ymd H:i:s')."\n";
        $remit->status = Remit::STATUS_BANK_PROCESS_FAIL;
        $remit->bank_status =  Remit::BANK_STATUS_FAIL;
        if($opUsername) $failMsg="{$opUsername} set fail at ".date('Ymd H:i:s')."\n";
        $remit->bak .=$failMsg;
        $remit->save();

        self::updateToRedis($remit);

        $remit = self::refund($remit);

        return $remit;
    }

    /*
     * 订单更新为已审核
     *
     * @param Remit $remit 订单对象
     * @param String $failMsg 失败描述信息
     */
    public static function setChecked(Remit $remit, $opUid=0, $opUsername='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        $remit->status = Remit::STATUS_CHECKED;
        if($opUsername) $bak="{$opUsername} set checked at ".date('Ymd H:i:s')."\n";
        $remit->bak .=$bak;
        $remit->save();

        self::updateToRedis($remit);

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
    public static function getStatus($orderNo = '',$merchantOrderNo = '', User $merchant)
    {
        if($merchantOrderNo && !$orderNo){
            $remit = Remit::findOne(['merchant_order_no'=>$merchantOrderNo,'merchant_id'=>$merchant->id]);
            if($remit) $orderNo = $remit->order_no;
        }

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$orderNo,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_REMIT_QUERY,
            'merchant_id'=>$merchant->id,
            'merchant_name'=>$merchant->username,
            'channel_account_id'=>Yii::$app->params['merchantPayment']->remitChannel->id,
            'channel_name'=>Yii::$app->params['merchantPayment']->remitChannel->channel_name,
        ];

        if(!$orderNo){
            Util::throwException(Macro::ERR_REMIT_NOT_FOUND);
        }

        $statusJson = Yii::$app->redis->hmget(self::REDIS_CACHE_KEY, $orderNo);

        $statusArr = [];
        if(!empty($statusJson[0])){
            $statusArr = \GuzzleHttp\json_decode($statusJson[0]);
        }

        return $statusArr;
    }

    static public function getOrderByOrderNo($orderNo){
        $order = Remit::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    /**
     * 当前是否可以提交到银行
     *
     * @param $remit
     * @return boolean
     * @author bootmall@gmail.com
     */
    public static function canCommitToBank($remit = null)
    {
        $enable = SiteConfig::cacheGetContent('enable_remit_commit');
        return $enable==1;
    }
}