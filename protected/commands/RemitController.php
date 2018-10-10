<?php
namespace app\commands;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\components\Util;
use app\jobs\RemitCommitJob;
use app\jobs\RemitQueryJob;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\log\LogHelper;
use Yii;
use yii\db\Query;

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

    /**
     * 检测处于银行处理状态出款订单的最新状态
     */
    public function actionCheckStatusQueueProducer(){
        $doCheck = true;
        $lastId = 0;
        $maxRecordInOneLoop = 200;
        while ($doCheck) {
            $_SERVER['LOG_ID'] = strval(uniqid());
            Yii::info('actionCheckStatusQueueProducer');
            //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
            $expire = SiteConfig::cacheGetContent('remit_check_expire');
            $startTs = time()-($expire?$expire*60:1800);

            $remits = (new Query())
                ->select(["id","order_no"])
                ->from(Remit::tableName())
                ->andWhere(['>', 'id', $lastId])
                ->andWhere(['status'=>Remit::STATUS_BANK_PROCESSING])
                ->andWhere(['>=', 'created_at', $startTs])
                ->limit($maxRecordInOneLoop)->all();
            Yii::info('find remit to check status: '.count($remits));
            foreach ($remits as $remit){
                $lastId = $remit['id'];
                Yii::info('remit to check status: '.$remit['order_no'].' lastId: '.$lastId);
                $job = new RemitQueryJob([
                    'orderNo'=>$remit['order_no'],
                ]);
                Yii::$app->remitQueryQueue->push($job);//->delay(10)
            }
            //没有可用订单了,或者订单量在最大值一半一下.重置上一个ID
            if(count($remits)<($maxRecordInOneLoop/2)){
                Yii::info('actionCheckStatusQueueProducer reset lastId to 0');
                $lastId = 0;
            }

            sleep(5);
        }
    }

    /**
     * 取出已审核出款并提交到银行待提交队列
     */
    public function actionBankCommitQueueProducer(){
        $doCheck = true;
        $lastId = 0;
        $maxRecordInOneLoop = 200;
        while ($doCheck) {
            $_SERVER['LOG_ID'] = strval(uniqid());
            $canCommit = LogicRemit::canCommitToBank();
            Yii::info(json_encode(['actionBankCommitQueueProducer',$canCommit]));
            if($canCommit){
                //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
                $expire = SiteConfig::cacheGetContent('remit_check_expire');
                $startTs = time()-($expire?$expire*60:1800);

                $remits = (new Query())
                    ->select(["id","order_no"])
                    ->from(Remit::tableName())
                    ->andWhere(['status'=>[Remit::STATUS_CHECKED]])
                    ->andWhere(['>', 'id', $lastId])
                    ->andWhere(['>=', 'updated_at', $startTs])
                    ->andWhere(['<', 'commit_to_bank_times', LogicRemit::MAX_TIME_COMMIT_TO_BANK])
                    ->orderBy("id ASC")
                    ->limit($maxRecordInOneLoop)->all();
                Yii::info('BankCommitQueueProducer find remit to commit bank: '.count($remits));

                foreach ($remits as $remit){
                    $lastId = $remit['id'];
                    Yii::info('BankCommitQueueProducer: '.$remit['order_no'].' lastId: '.$lastId);

                    $job = new RemitCommitJob([
                        'orderNo'=>$remit['order_no'],
                        'force'=>false,
                    ]);
                    Yii::$app->remitBankCommitQueue->push($job);//->delay(10)
                }

                //没有可用订单了,重置上一个ID
                if(count($remits)<($maxRecordInOneLoop/2)){
                    Yii::info('BankCommitQueueProducer reset lastId to 0');
                    $lastId = 0;
                }
            }else{
                Yii::info('system set stop commit to bank');
            }

            sleep(5);
        }
    }

    /**
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
                ->where(['status'=>Remit::STATUS_NOT_REFUND])
                ->andWhere(['>=', 'remit_at', $startTs])
                ->limit(100)->all();

            foreach ($remits as $remit){
                Yii::info('job remit ReCheckFail: '.$remit->order_no);

                $job = new RemitQueryJob([
                    'orderNo'=>$remit->order_no,
                ]);
                Yii::$app->remitQueryQueue->push($job);//->delay(10)
            }

            sleep(mt_rand(20,40));
        }
    }


    /**
     * 查询出待通知订单并放到通知队列
     *
     * 队列本身已经有重试机制，这个地方不需要太频繁
     */
    public function actionNotifyQueueProducer(){
        $doCheck = true;
        $lastId = 0;
        $maxRecordInOneLoop = 100;
        while ($doCheck) {
            $_SERVER['LOG_ID'] = strval(uniqid());
            //获取配置:出款多少分钟之后不再自动查询状态,默认半小时
            $expire = SiteConfig::cacheGetContent('order_notify_expire');
            $remitMaxNotifyTimes = SiteConfig::cacheGetContent('remit_max_notify_times');
            $remitMaxNotifyTimes = $remitMaxNotifyTimes?$remitMaxNotifyTimes:1;
            $startTs = time()-($expire?$expire*60:1800);

            $query = Remit::find()
                ->where(['status'=>[Remit::STATUS_SUCCESS, Remit::STATUS_REFUND], 'notify_status'=>[Remit::NOTICE_STATUS_NONE, Remit::NOTICE_STATUS_FAIL]])
                ->andWhere(['>', 'id', $lastId])
                ->andWhere(['!=', 'notify_url', ''])
                ->andWhere(['>=', 'created_at', $startTs])
                //最多通知10次
                ->andWhere(['<', 'notify_times', $remitMaxNotifyTimes])
                //已经到达通知时间
                ->andWhere(['or',['next_notify_time'=>0],['>=', 'next_notify_time', time()]]);

            $orders = $query->limit($maxRecordInOneLoop)->all();
            Yii::info('find remit to notify: '.count($orders));
            foreach ($orders as $order){
                $lastId = $order->id;
                Yii::info('remit notify: '.$order->order_no);
                LogicRemit::notify($order);
            }
            //没有可用订单了,重置上一个ID
            if(count($orders)<($maxRecordInOneLoop/2)){
                Yii::info('notifyQueueProducer reset lastId to 0');
                $lastId = 0;
            }

            sleep(mt_rand(10,20));
        }
    }

    /**
     * 检测出款自动停用/启用时间并进行对应配置
     */
    public function actionSetAutoCommitStatusCron()
    {

        $doCheck = true;
        while ($doCheck) {
            $_SERVER['LOG_ID'] = strval(uniqid());

            $todayStr     = date("Y-m-d");
            $nowTs        = time();
            $inStopFrames = false;
            $status       = intval(LogicRemit::canCommitToBank());
            $framesConfig = SiteConfig::cacheGetContent('remit_stop_time_frames');
            $errMsg       = '';

            if ($framesConfig) {
                $frames = explode("\n", $framesConfig);
//                Yii::info(__FUNCTION__ . " get config : ".implode('; ',$frames).", now status: ".intval($status));
                foreach ($frames as $f){
//                    Yii::info(__FUNCTION__ . " get frames : {$f}");
                    if(!$f){
                        continue;
                    }
                    $fArr = explode("-", str_replace(' ', '', $f));
                    if (count($fArr) != 2) {
                        $errMsg .= "必须有开始结束时间且以'-'分割:{$f};";
                    }
                    $startStr = $todayStr . ' ' . $fArr[0];
                    $endStr = $todayStr . ' ' . $fArr[1];
                    $startTs = strtotime($startStr);
                    $endTs   = strtotime($endStr);

                    if (!$startTs || !$endTs || $startTs >= $endTs) {
                        $errMsg .= "开始结束时间不合法:{$f};";
                    }

                    if ($errMsg) {
                        Yii::error(__FUNCTION__ . " 自动启用停用出款提交上游配置错误: {$errMsg}");
                        break;
                    }

                    //在禁用时间区间
                    if ($nowTs >= $startTs && $nowTs <= $endTs) {
                        $inStopFrames = true;
                        //当前为启用的,进行停用
                        if ($status) {
                            $msg = "模拟测试,当前为禁用提交时间,将进行停用: {$f},now status:{$status},now ts:".date("Y-m-d H:i:s")."/{$nowTs},{$startStr},{$startTs},{$endStr},{$endTs}";
                            Yii::info(__FUNCTION__ . $msg);
                            $cacheKey = md5(__FUNCTION__ .$f);
                            if(!Yii::$app->cache->get($cacheKey)){
                                Util::sendTelegramMessage($msg);
                                Yii::$app->cache->set($cacheKey,time(),86400);
                            }
//                            LogicRemit::stopBankCommit();
                            break;
                        }
                    }
//                    Yii::info(__FUNCTION__ . " not in");
                }

                //不处于停用区间,且当前状态为停用的,进行启用
                if (!$errMsg && !$inStopFrames && !$status) {
                    $msg = "模拟测试,当前为启用提交时间,将进行启用: now status:{$status},now time:".date("Y-m-d H:i:s").", ".implode('; ',$frames);
                    Yii::info(__FUNCTION__ . $msg);
                    $cacheKey = md5(__FUNCTION__);
                    if(!Yii::$app->cache->get($cacheKey)){
                        Util::sendTelegramMessage($msg);
                        Yii::$app->cache->set($cacheKey,time(),7200);
                    }
//                    LogicRemit::startBankCommit();
                }
            }

            sleep(2);
        }

    }
}
