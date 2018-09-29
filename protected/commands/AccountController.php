<?php
namespace app\commands;
use app\common\models\model\ChannelAccount;
use app\common\models\model\SiteConfig;
use app\common\models\model\User;
use app\common\models\model\UserPaymentInfo;
use app\components\Util;
use Yii;

class AccountController extends BaseConsoleCommand
{
    public function init()
    {
        parent::init();
    }

    public function beforeAction($event)
    {
        Yii::info('console process: '.implode(' ',$_SERVER['argv']));
        return parent::beforeAction($event);
    }

    /*
     * 重置用户及通道的日收款出款累计金额
     *
     * ./protected/yii account/reset-quota
     */
    public function actionResetQuota()
    {
        UserPaymentInfo::updateAll(['remit_today' => 0, 'recharge_today' => 0]);
        ChannelAccount::updateAll(['remit_today' => 0, 'recharge_today' => 0]);
    }


    /**
     * 商户余额检测报警
     *
     * ./protected/yii account/balance-check
     */
    public function actionBalanceCheck(){
        $doCheck = true;

        while ($doCheck) {
            $maxAlertBalance = SiteConfig::cacheGetContent('account_balance_alert_quota');
            $maxBanBalance = SiteConfig::cacheGetContent('account_balance_ban_quota');
            if(!$maxAlertBalance) return;

            $accounts = User::find()->where(['>=','balance',$maxAlertBalance])->all();
            $alertArr = [];
            foreach ($accounts as $account){
                $redisKey = "account:balance_alert:{$account->id}";
                if(!Yii::$app->redis->get($redisKey)){
                    $msg = "商户: {$account->username},当前余额:{$account->balance}";

                    //达到关闭阀值,关闭充值
                    if(bccomp($account->balance,$maxBanBalance) === 1){
                        $account->paymentInfo->allow_api_recharge = 0;
                        $account->paymentInfo->allow_manual_recharge = 0;
                        $account->paymentInfo->save();
                        $msg.",大于关停阀值{$maxBanBalance},充值已被关停.";
                    }
                    $alertArr[] = $msg;
                    //一个小时只报警一次
                    Yii::$app->redis->set($redisKey,time());
                    Yii::$app->redis->expire($redisKey,600);
                }

            }
            if($alertArr) Util::sendTelegramMessage("商户余额大于报警阀值{$maxAlertBalance}. \n".implode($alertArr,"\n "));
            sleep(120);
        }
    }

}
