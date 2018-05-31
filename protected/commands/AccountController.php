<?php
namespace app\commands;
use app\common\models\model\ChannelAccount;
use app\common\models\model\UserPaymentInfo;
use Yii;

class AccountController extends BaseConsoleCommand
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
     * 重置用户及通道的日收款出款累计金额
     *
     * ./protected/yii account/reset-quota
     */
    public function actionResetQuota(){
        UserPaymentInfo::updateAll(['remit_today' => 0,'recharge_today' => 0]);
        ChannelAccount::updateAll(['remit_today' => 0,'recharge_today' => 0]);
    }
}
