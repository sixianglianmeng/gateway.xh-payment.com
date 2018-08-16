<?php
namespace app\jobs;

use app\components\Util;
use Yii;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\modules\gateway\models\logic\LogicOrder;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;
use app\common\models\model\Order;

class PaymentNotifyJob extends BaseObject implements RetryableJobInterface
{
    public $orderNo;
    public $url;
    public $data;

    public function execute($queue)
    {
        Yii::info('got PaymentNotifyJob ret: '.$this->orderNo.' '.http_build_query($this->data));
        $ts = microtime(true);
        $orderNo = $this->orderNo;

        $url = $this->url;
        try{
            $body = Util::curlPostJson($url,$this->data,[],10000);
            $httpCode = 200;

        }catch (\Exception $e){
            $httpCode = $e->getCode();
            $body = $e->getMessage();
        }

        Yii::info('PaymentNotifyJob ret: '.$this->orderNo.' '.$httpCode.' '.$body);
        $costTime = bcsub(microtime(true),$ts,4);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [];
        Yii::$app->params['apiRequestLog']['event_id']=$orderNo;
        Yii::$app->params['apiRequestLog']['event_type']=LogApiRequest::EVENT_TYPE_OUT_RECHARGE_NOTIFY;
        LogicApiRequestLog::outLog($url, 'POST', $body, $httpCode, $costTime, $this->data);

        $noticeOk = Order::NOTICE_STATUS_NONE;
        $bodyFiltered = str_replace(['"',"'"],'',strtolower($body));
        if($bodyFiltered=='success'){
            $noticeOk = Order::NOTICE_STATUS_SUCCESS;
        }else{
            $noticeOk = Order::NOTICE_STATUS_FAIL;
        }
        LogicOrder::updateNotifyResult($this->orderNo,$noticeOk,$body);

        return true;
    }

    public function getRetryDelay($attempt, $error)
    {
        //通知分钟数: 2,10,28,60,110,182,280,408,570,770
        return $attempt * $attempt * 120;
    }

    public function getTtr()
    {
        return 30;
    }

    public function canRetry($attempt, $error)
    {
        return false;//($attempt < 10);// && ($error instanceof TemporaryException)
    }
}