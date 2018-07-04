<?php
namespace app\commands;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\jobs\RemitCommitJob;
use app\jobs\RemitQueryJob;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\log\LogHelper;
use Yii;

class RemitController extends BaseConsoleCommand
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
     * 检测处于银行处理状态出款订单的最新状态
     */
    public function actionCheckStatusQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            Yii::info('actionCheckStatusQueueProducer');
            //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
            $expire = SiteConfig::cacheGetContent('remit_check_expire');
            $startTs = time()-($expire?$expire*60:1800);

            $remits = Remit::find()
            ->andWhere(['status'=>Remit::STATUS_BANK_PROCESSING])
            ->andWhere(['>=', 'created_at', $startTs])
            ->limit(100)->all();

            Yii::info('find remit to check status: '.count($remits));
            foreach ($remits as $remit){
                Yii::info('remit status check: '.$remit->order_no);
                $job = new RemitQueryJob([
                    'orderNo'=>$remit->order_no,
                ]);
                Yii::$app->remitQueryQueue->push($job);//->delay(10)
            }

            sleep(mt_rand(5,10));
        }
    }

    /*
     * 取出已审核出款并提交到银行待提交队列
     */
    public function actionBankCommitQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            Yii::info('actionBankCommitQueueProducer');
            if(LogicRemit::canCommitToBank()){
                //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
                $expire = SiteConfig::cacheGetContent('remit_check_expire');
                $startTs = time()-($expire?$expire*60:1800);

                $remits = Remit::find()
                    ->andWhere(['status'=>[Remit::STATUS_CHECKED]])
                    ->andWhere(['>=', 'updated_at', $startTs])
                    ->andWhere(['<', 'commit_to_bank_times', LogicRemit::MAX_TIME_COMMIT_TO_BANK])
                    ->limit(100)->all();

                Yii::info('find remit to commit bank: '.count($remits));
                foreach ($remits as $remit){
                    Yii::info('BankCommitQueueProducer: '.$remit->order_no);

                    $job = new RemitCommitJob([
                        'orderNo'=>$remit->order_no,
                    ]);
                    Yii::$app->remitBankCommitQueue->push($job);//->delay(10)
                }
            }else{
                Yii::info('system set stop commit to bank');
            }

            sleep(mt_rand(5,10));
        }
    }

    /*
     * 取出失败出款并提交到查询队列
     *
     * 某些出款渠道不稳定，失败情况下需要再次查询核实
     */
    public function actionReCheckFailQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
            $expire = SiteConfig::cacheGetContent('remit_check_expire');
            $startTs = time()-($expire?$expire*60:1800);

            $remits = Remit::find()
                ->where(['status'=>Remit::STATUS_BANK_PROCESS_FAIL])
                ->andWhere(['>=', 'remit_at', $startTs])
                ->limit(100)->all();


            foreach ($remits as $remit){
                Yii::info('job remit ReCheckFail: '.$remit->order_no);

                $job = new RemitQueryJob([
                    'orderNo'=>$remit->order_no,
                ]);
                Yii::$app->remitQueryQueue->push($job);//->delay(10)
            }

            sleep(mt_rand(5,10));
        }
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
            $remitMaxNotifyTimes = SiteConfig::cacheGetContent('remit_max_notify_times');
            $remitMaxNotifyTimes = $remitMaxNotifyTimes?$remitMaxNotifyTimes:1;
            $startTs = time()-($expire?$expire*60:1800);

            $query = Remit::find()
                ->where(['status'=>Remit::STATUS_SUCCESS,'notify_status'=>[Remit::NOTICE_STATUS_NONE,Remit::NOTICE_STATUS_FAIL]])
                ->andWhere(['!=', 'notify_url', ''])
                ->andWhere(['>=', 'remit_at', $startTs])
                //最多通知10次
                ->andWhere(['<', 'notify_times', $remitMaxNotifyTimes])
                //已经到达通知时间
                ->andWhere(['or',['next_notify_time'=>0],['>=', 'next_notify_time', time()]]);

            $orders = $query->limit(100)->all();
            Yii::info('find remit to notify: '.count($orders));
            foreach ($orders as $order){
                Yii::info('remit notify: '.$order->order_no);
                LogicRemit::notify($order);
            }

            sleep(mt_rand(5,30));
        }
    }
}
