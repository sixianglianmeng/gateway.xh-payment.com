<?php

namespace app\common\models\logic;

use app\common\models\model\LogApiRequest;
use app\common\models\model\Order;
use app\components\Util;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;

/*
 * api请求日志
 */
class LogicApiRequestLog
{
    /**
     * 记录日志
     *
     * @param array $logResponse 响应数据
     */
    public static function inLog($logResponse, $force=false)
    {
        //设置了请求日志，写入日志表
        if(!empty(Yii::$app->params['apiRequestLog'])){
            $logData = Yii::$app->params['apiRequestLog'];
            $logData['event_type'] = $logData['event_type']??'event_type';
            $logData['event_id'] = $logData['event_id']??'event_id';
            $logCheckKey = 'apiRequestLogWrited'.$logData['event_type'].$logData['event_id'];
            if(!$force && !empty(Yii::$app->params[$logCheckKey])){
                return true;
            }

            Yii::$app->params[$logCheckKey] = 1;
            if(isset($logData['post_data']) && !is_string($logData['post_data'])){
                $logData['post_data'] = Util::json_encode($logData['post_data']);
            }

            if(Yii::$app->request->isConsoleRequest){
                $logData['request_url']  = '';
                $logData['request_method'] = '';
                $logData['post_data'] =  '';
                $logData['http_status'] =  '';
                $logData['device_id'] = '';
                $logData['remote_ip'] = '';
                $logData['referer'] = '';
                $logData['useragent'] = '';
            }else{
                $logData['request_url'] = Yii::$app->request->hostInfo.Yii::$app->request->getUrl();
                $logData['request_method'] = Yii::$app->request->method=='GET'?1:2;
                $logData['post_data'] = $logData['post_data']??Util::json_encode(Yii::$app->getRequest()->getBodyParams());
            	$logData['response_data'] = Util::json_encode($logResponse);
                $logData['http_status'] = Yii::$app->response->statusCode;
                $logData['remote_ip'] = Util::getClientIp();
                $logData['referer'] = Yii::$app->request->referrer??'';
                $logData['useragent'] = Yii::$app->request->userAgent??'';
                $cookies = Yii::$app->request->cookies;
                $uuid = empty($cookies[PaymentRequest::CLIENT_ID_IN_COOKIE])?'':$cookies[PaymentRequest::CLIENT_ID_IN_COOKIE]->value;
                $logData['device_id'] = $uuid;
            }

            $logData['cost_time'] = ceil(Yii::getLogger()->getElapsedTime()*1000);
            $logData['channel_account_id'] = $logData['channel_account_id']??0;
            $logData['channel_name'] = $logData['channel_name']??'';
            $logData['deleted_at'] = 0;
            $logData['merchant_order_no'] = $logData['merchant_order_no']??'';

            $apiRequestLog = new LogApiRequest();
            $apiRequestLog->setAttributes($logData,false);
            $apiRequestLog->save();
        }
    }

    /**
     * 记录日志
     *
     * @param string $url 请求地址
     * @param string $method 请求方法get/post
     * @param array $logResponse 响应数据
     * @param array $logResponse 响应数据
     * @param int $httpStatus http请求状态
     * @param float $costTime 请求消耗时间
     * @param array $logRequest 请求数据
     */
    public static function outLog($url, $method, $logResponse, $httpStatus, $costTime = 0, $logRequest=[], $force=false)
    {
        //设置了请求日志，写入日志表
        if(!empty(Yii::$app->params['apiRequestLog'])){
            $logData = Yii::$app->params['apiRequestLog'];

            $logCheckKey = 'apiRequestLogWrited'.$logData['event_type'].$logData['event_id'];
            if(!$force && !empty(Yii::$app->params[$logCheckKey])){
                return true;
            }

            Yii::$app->params[$logCheckKey] = 1;

            $logData['request_url'] = $url;
            $logData['request_method'] = strtoupper($method)=='GET'?1:2;
            $logData['post_data'] = is_string($logRequest)?$logRequest:json_encode($logRequest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $logData['response_data'] = json_encode($logResponse,JSON_UNESCAPED_UNICODE);
            $logData['http_status'] = $httpStatus;
            $logData['remote_ip'] = '';
            $logData['referer'] = '';
            $logData['useragent'] = '';
            $logData['device_id'] = '';
            $logData['merchant_id'] = $logData['merchant_id']??0;
            $logData['merchant_name'] = $logData['merchant_name']??'';
            $logData['channel_account_id'] = $logData['channel_account_id']??0;
            $logData['cost_time'] = $costTime;
            $logData['channel_name'] = $logData['channel_name']??'';
            $logData['merchant_order_no'] = $logData['merchant_order_no']??'';
            $logData['channel_order_no'] = $logData['channel_order_no']??'';

            $apiRequestLog = new LogApiRequest();
            $apiRequestLog->setAttributes($logData,false);
            $apiRequestLog->save();
        }
    }

    /**
     * 记录收款订单提交到三方日志
     *
     * @param Order $order 收款订单对象
     * @param string $url 请求地址
     * @param string $response 响应数据
     * @param array $requestData 请求数据
     */
    public static function rechargeAddLog(Order $order, string $url, string $response, array $requestData=[])
    {
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->order_no,
            'merchant_order_no'=>$order->merchant_order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$order->merchant_id,
            'merchant_name'=>$order->merchant_account,
            'channel_account_id'=>$order->channelAccount->id,
            'channel_name'=>$order->channelAccount->channel_name,
        ];

        LogicApiRequestLog::outLog($url, 'POST', $response, 200,0, $requestData, true);
    }
}