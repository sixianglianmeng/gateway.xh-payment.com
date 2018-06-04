<?php
namespace app\commands;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\jobs\PaymentNotifyJob;
use app\jobs\RemitCommitJob;
use app\jobs\RemitQueryJob;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\log\LogHelper;
use Yii;

class OrderController extends BaseConsoleCommand
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
     * 查询出待通知订单并放到通知队列
     *
     * 队列本身已经有重试机制，这个地方不需要太频繁
     */
    public function actionNotifyQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
            $expire = SiteConfig::cacheGetContent('order_notify_expire');
            $startTs = time()-($expire?$expire*60:1800);

            $query = Order::find(['status'=>Order::STATUS_PAID,'notice_status'=>[Order::NOTICE_STATUS_NONE,Order::NOTICE_STATUS_FAIL]])
            ->andWhere(['>=', 'paid_at', $startTs])
            //最多通知10次
            ->andWhere(['<', 'notify_times', 10])
            //已经到达通知时间
            ->andWhere(['or',['next_notify_time'=>0],['>=', 'next_notify_time', time()]]);

            $orders = $query->limit(100)->all();
            Yii::info('find order to notify: '.count($orders));
            foreach ($orders as $order){
                Yii::info('order notify: '.$order->order_no);
                LogicOrder::notify($order);
            }

            sleep(mt_rand(5,30));
        }
    }
}
