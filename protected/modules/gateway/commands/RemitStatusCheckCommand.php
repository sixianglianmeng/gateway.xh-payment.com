<?php
namespace app\commands;
use app\common\models\model\Remit;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;

class RemitStatusCheckCommand extends BaseConsoleCommand
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

    public function actionCheck(){
        $doCheck = true;
        $paymentChannelAccount = LogicChannelAccount::getDefaultRemitChannelAccount();
        if(!$paymentChannelAccount){
            Yii::error('默认提款渠道配置错误');
            throw new \Exception('提款渠道配置错误');
            return faslse;
        }
        while (true) {
            $remits = Remit::find(['status'=>Remit::STATUS_BANK_PROCESSING])->limit(100)->all();
            foreach ($remits as $remit){
                LogicRemit::queryChannelRemitStatus($remit,$paymentChannelAccount);
            }
        }

    }
}
