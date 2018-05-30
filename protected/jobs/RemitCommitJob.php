<?php
namespace app\jobs;

use app\common\models\model\Remit;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;
use yii\base\BaseObject;

/*
 * 提交提交请求到银行
 */
class RemitCommitJob extends BaseObject implements \yii\queue\JobInterface
{
    public $orderNo;

    public function execute($queue)
    {
        Yii::debug(['got RemitCommitJob ret',$this->orderNo]);

        $remit = Remit::findOne(['order_no'=>$this->orderNo]);
        if(!$remit){
            Yii::warning('JobRemitCommit error, empty remit:'.$this->orderNo);
            return true;
        }

        LogicRemit::commitToBank($remit, $remit->channelAccount);
    }
}