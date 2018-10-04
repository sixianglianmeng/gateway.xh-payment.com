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
    public $force = false;

    public function execute($queue)
    {
        Yii::info('got RemitCommitJob ret '.$this->orderNo);

        $remit = Remit::findOne(['order_no'=>$this->orderNo]);
        if(!$remit){
            Yii::warning('JobRemitCommit error, empty remit:'.$this->orderNo);
            return true;
        }

        $ret = LogicRemit::commitToBank($remit, boolval($this->force));
        if($ret) LogicRemit::updateToRedis($remit);
    }
}