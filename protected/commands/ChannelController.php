<?php
namespace app\commands;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\components\Macro;
use app\components\Util;
use app\jobs\RemitCommitJob;
use app\jobs\RemitQueryJob;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\log\LogHelper;
use Yii;

class ChannelController extends BaseConsoleCommand
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

    /**
     * 更新三方平台账户余额
     *
     *  @param int $runOnce 是否只执行一次 0否，1是
     * ./protected/yii channel/update-account-balance
     */
    public function actionUpdateAccountBalance(int $runOnce = 0){
        $doCheck = true;

        while ($doCheck) {
            $lastUpdateKey = "last_chanell_account_update_ts";
            $lastUpdate = Yii::$app->cache->get($lastUpdateKey);

            $accounts = ChannelAccount::findAll(['status'=>ChannelAccount::STATUS_ACTIVE]);
            Yii::info('find channel accounts to check balance: '.count($accounts));
            foreach ($accounts as $account){
                LogicChannelAccount::syncBalance($account);
            }
            Yii::$app->cache->set($lastUpdateKey,time());

            if($runOnce==1){
                $doCheck = false;
            }else{
                sleep(300);
            }

        }
    }


    /**
     * 渠道号余额检测报警
     *
     * ./protected/yii channel/account-balance-check
     */
    public function actionAccountBalanceCheck(){
        $doCheck = true;

        while ($doCheck) {
            $threshold = SiteConfig::cacheGetContent('channel_balance_alert_threshold');

            $accounts = ChannelAccount::findAll(['status'=>ChannelAccount::STATUS_ACTIVE]);
            foreach ($accounts as $account){
                if($account->balance>0 && $account->balance<=$threshold){
                    Util::sendTelegramMessage("通道余额不足. 通道号: {$accounts->channel_name},当前余额:{$account->balance}");
                }
            }

            sleep(120);
        }
    }
}
