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
        Yii::debug('console process: '.implode(' ',$_SERVER['argv']));
        return parent::beforeAction($event);
    }

    /*
     * 更新三方平台账户余额
     */
    public function actionUpdateAccountBalance(){
        $doCheck = true;

        while ($doCheck) {
            $accounts = ChannelAccount::findAll(['status'=>ChannelAccount::STATUS_ACTIVE]);
            Yii::info('find channel accounts to check balance: '.count($accounts));
            foreach ($accounts as $account){
                LogicChannelAccount::syncBalance($account);
            }

            sleep(mt_rand(5,10));
        }
    }
}
