<?php
namespace app\jobs;

use app\common\models\model\Remit;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

class RemitQueryJob extends BaseObject implements RetryableJobInterface
{
    public $orderNo;
    public $url;
    public $data;

    public function execute($queue)
    {
        Yii::info('RemitQueryJob remit to check status : '.$this->orderNo,' pid: '.getmypid());
        $remit = Remit::findOne(['order_no'=>$this->orderNo]);
        if(!$remit){
            Yii::warning('JobRemitQuery error, empty remit:'.$this->orderNo);
            return true;
        }

        LogicRemit::queryChannelRemitStatus($remit);
    }

    public function getRetryDelay($attempt, $error)
    {
        return 60;
    }

    public function getTtr()
    {
        return 30;
    }

    public function canRetry($attempt, $error)
    {
        return ($attempt < 2);// && ($error instanceof TemporaryException)
    }
}