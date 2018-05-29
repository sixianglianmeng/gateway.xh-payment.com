<?php
namespace app\jobs;

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
        Yii::debug(['got PaymentNotifyJob ret',$this->orderNo,http_build_query($this->data)]);
        $ts = microtime(true);
        $orderNo = $this->orderNo;

        $url = $this->url.'?'.http_build_query($this->data);
        try{
            $client = new \GuzzleHttp\Client();
//            $response = $client->request('POST', $this->url, [
//                'timeout' => 5,
//                'body' => http_build_query($this->data)
//            ]);
            $response = $client->get($url);
            $httpCode = $response->getStatusCode();
            $body = (string)$response->getBody();
        }catch (\Exception $e){
            $httpCode = $e->getCode();
            $body = $e->getMessage();
            if($httpCode==200 || empty($httpCode)) $httpCode=-1;
        }

        Yii::debug('PaymentNotifyJob ret: '.$this->orderNo.' '.$httpCode.' '.$body);
        $costTime = bcsub(microtime(true),$ts,4);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [];
        Yii::$app->params['apiRequestLog']['event_id']=$orderNo;
        Yii::$app->params['apiRequestLog']['event_type']=LogApiRequest::EVENT_TYPE_OUT_RECHARGE_NOTIFY;
        LogicApiRequestLog::outLog($url, 'POST', $body, $httpCode, $costTime, $this->data);

        $noticeOk = Order::NOTICE_STATUS_NONE;
        if($httpCode == 200){
            $noticeOk = Order::NOTICE_STATUS_SUCCESS;
        }else{
            $noticeOk = Order::NOTICE_STATUS_FAIL;
        }
        LogicOrder::updateNotifyResult($this->orderNo,$noticeOk,$body);

        //        $urlInfo = parse_url($this->url);
//        \Swoole\Async::dnsLookup($urlInfo['host'], function ($domainName, $ip) use($urlInfo,$queue,$orderNo,$ts) {
//
//            $cli = new \swoole_http_client($ip, 80);
//            $cli->set([ 'timeout' => 10]);
//            $cli->setHeaders([
//                'Host' => $domainName,
//                "User-Agent" => 'Chrome/49.0.2587.3',
//                'Accept' => 'text/html,application/xhtml+xml,application/xml',
//                'Accept-Encoding' => 'gzip',
//            ]);
//            $urlInfo['path'] = $urlInfo['path']??'/';
//            Yii::debug(['PaymentNotifyJob before post ',$this->orderNo,$urlInfo]);
//            $cli->post($urlInfo['path'], $this->data, function ($cli) use ($queue,$orderNo,$ts) {
//                Yii::debug(['PaymentNotifyJob ret',$this->orderNo,$cli->statusCode]);
//                $costTime = bcsub(microtime(true),$ts,4);
//
//                //接口日志埋点
//                Yii::$app->params['apiRequestLog'] = [];
//                Yii::$app->params['apiRequestLog']['event_id']=$orderNo;
//                Yii::$app->params['apiRequestLog']['event_type']=LogApiRequest::EVENT_TYPE_OUT_RECHARGE_NOTIFY;
////                    Yii::$app->params['apiRequestLog']['merchant_id']=$order->merchant_id??$merchant->id;
////                    Yii::$app->params['apiRequestLog']['merchant_name']=$order->merchant_account??$merchant->username;
////                    Yii::$app->params['apiRequestLog']['channel_account_id']=$order->channelAccount->id;
////                    Yii::$app->params['apiRequestLog']['channel_name']=$order->channelAccount->channel_name;
//                LogicApiRequestLog::outLog($this->url, 'POST', $cli->body, $cli->statusCode, $costTime, $this->data);
//
//                $noticeOk = Order::NOTICE_STATUS_NONE;
//                if($cli->statusCode == 200){
//                    $noticeOk = Order::NOTICE_STATUS_SUCCESS;
//                }else{
//                    $noticeOk = Order::NOTICE_STATUS_FAIL;
//                }
//                LogicOrder::updateNotifyResult($this->orderNo,$noticeOk,$cli->body);
//            });
//        });

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
        return ($attempt < 10);// && ($error instanceof TemporaryException)
    }
}