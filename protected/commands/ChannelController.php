<?php
namespace app\commands;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Remit;
use app\components\Macro;
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

    /*
     * 更新三方平台账户余额
     */
    public function actionUpdateAccountBalance(){
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

            sleep(mt_rand(600,1200));
        }
    }
}
