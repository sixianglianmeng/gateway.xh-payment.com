<?php
namespace app\jobs;

use app\common\models\model\Order;
use app\modules\gateway\models\logic\LogicOrder;
use Yii;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

class OrderQueryJob extends BaseObject implements RetryableJobInterface
{
    public $orderNo;
    public $url;
    public $data;

    public function execute($queue)
    {
        Yii::info(['got OrderQueryJob ret',$this->orderNo]);

        $order = Order::findOne(['order_no'=>$this->orderNo]);
        if(!$order){
            Yii::warning('JobOrderQuery error, empty remit:'.$this->orderNo);
            return true;
        }

        LogicOrder::queryChannelOrderStatus($order);
    }

    public function getRetryDelay($attempt, $error)
    {
        return 60;
    }

    /*
     * Max time for job execution
     */
    public function getTtr()
    {
        return 30;
    }

    public function canRetry($attempt, $error)
    {
        return ($attempt < 2);// && ($error instanceof TemporaryException)
    }
}