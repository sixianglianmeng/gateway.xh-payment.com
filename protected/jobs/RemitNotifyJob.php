<?php
namespace app\jobs;

use app\components\Util;
use Yii;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\modules\gateway\models\logic\LogicRemit;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;
use app\common\models\model\Remit;

class RemitNotifyJob extends BaseObject implements RetryableJobInterface
{
    public $orderNo;
    public $url;
    public $data;
    public $format;

    public function execute($queue)
    {
        Yii::info('got RemitNotifyJob ret: '.$this->orderNo.' '.http_build_query($this->data));
        $ts = microtime(true);
        $orderNo = $this->orderNo;

        $url = $this->url;
        try{

//            $body = Util::curlPostJson($url,$this->data, [], 10000);
            $method = 'post';
            $format = 'json';
            if($this->format){
                $formatRule = json_decode($this->format,true);
                if($formatRule['method']) $method = $formatRule['method'];
                if($formatRule['format']) $format = $formatRule['format'];
            }
            $body = Util::sendCurlHttpRequest($url,$this->data,[],10000,$method,$format);
            $httpCode = 200;
        }catch (\Exception $e){
            $httpCode = $e->getCode();
            $body = $e->getMessage();
        }

        Yii::info("RemitNotifyJob ret: {$this->orderNo} {$this->url} {$this->format} {$body} {$httpCode}");
        $costTime = bcsub(microtime(true),$ts,4);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [];
        Yii::$app->params['apiRequestLog']['event_id']=$orderNo;
        Yii::$app->params['apiRequestLog']['event_type']=LogApiRequest::EVENT_TYPE_OUT_REMIT_NOTIFY;
        LogicApiRequestLog::outLog($url, 'POST', $body, $httpCode, $costTime, $this->data);

        $noticeOk = Remit::NOTICE_STATUS_NONE;
        $bodyFiltered = str_replace(['"',"'"],'',strtolower($body));
        if($bodyFiltered=='success'){
            $noticeOk = Remit::NOTICE_STATUS_SUCCESS;
        }else{
            $noticeOk = Remit::NOTICE_STATUS_FAIL;
        }
        LogicRemit::updateNotifyResult($this->orderNo,$noticeOk,$body);

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