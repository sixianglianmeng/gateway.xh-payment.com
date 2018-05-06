<?php
namespace app\jobs;

use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\modules\gateway\models\logic\LogicOrder;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\RetryableJobInterface;
use app\common\models\model\Order;

class PaymentNotifyJob extends BaseObject implements RetryableJobInterface
{
    public $orderNo;
    public $url;
    public $data;

    public function execute($queue)
    {
        $urlInfo = parse_url($this->url);

        $ts = microtime(true);
        \Swoole\Async::dnsLookup($urlInfo['host'], function ($domainName, $ip) use($urlInfo,$queue,$ts) {
            $cli = new \swoole_http_client($ip, 80);
            $cli->set([ 'timeout' => 10]);
            $cli->setHeaders([
                'Host' => $domainName,
                "User-Agent" => 'Chrome/49.0.2587.3',
                'Accept' => 'text/html,application/xhtml+xml,application/xml',
                'Accept-Encoding' => 'gzip',
            ]);
            $urlInfo['path'] = $urlInfo['path']??'/';
            $cli->post($urlInfo['path'], $this->data, function ($cli) use ($queue,$ts) {
                \Yii::debug(['PaymentNotifyJob ret',$this->orderNo,$cli->statusCode]);
                $costTime = bcsub(microtime(true),$ts,4);
                LogApiRequest::outLog($this->url, 'POST', $cli->body, $cli->statusCode, $costTime, $this->data);
                //接口日志埋点
                Yii::$app->params['apiRequestLog'] = [];

                    Yii::$app->params['apiRequestLog']['event_id']=$order->order_no;
                    Yii::$app->params['apiRequestLog']['event_type']=LogApiRequest::EVENT_TYPE_IN_RECHARGE_QUER;
                    Yii::$app->params['apiRequestLog']['merchant_id']=$order->merchant_id??$merchant->id;
                    Yii::$app->params['apiRequestLog']['merchant_name']=$order->merchant_account??$merchant->username;
                    Yii::$app->params['apiRequestLog']['channel_account_id']=$order->channelAccount->id;
                    Yii::$app->params['apiRequestLog']['channel_name']=$order->channelAccount->channel_name;

                $noticeOk = Order::NOTICE_STATUS_NONE;
                if($cli->statusCode == 200){
                    $noticeOk = Order::NOTICE_STATUS_SUCCESS;
                }else{
                    $noticeOk = Order::NOTICE_STATUS_FAIL;
                }
                LogicOrder::updateNotifyResult($this->orderNo,$noticeOk,$cli->body);
            });
        });
    }

    public function getRetryDelay($attempt, $error)
    {
        return $attempt * 30;
    }

    public function getTtr()
    {
        return 100;
    }

    public function canRetry($attempt, $error)
    {
        return ($attempt < 10);// && ($error instanceof TemporaryException)
    }
}