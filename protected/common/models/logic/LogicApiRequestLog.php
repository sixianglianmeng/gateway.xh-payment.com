<?php

namespace app\common\models\logic;

use app\common\models\model\LogApiRequest;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;
use yii\data\ActiveDataProvider;

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
    public static function inLog($logResponse)
    {
        //设置了请求日志，写入日志表
        if(!empty(Yii::$app->params['apiRequestLog'])){
            $logData = Yii::$app->params['apiRequestLog'];
            $logData['request_url'] = Yii::$app->request->hostInfo.Yii::$app->request->getUrl();
            $logData['request_method'] = Yii::$app->request->method=='GET'?1:2;
            $logData['post_data'] = json_encode(Yii::$app->getRequest()->getBodyParams(),JSON_UNESCAPED_UNICODE);
            $logData['response_data'] = json_encode($logResponse,JSON_UNESCAPED_UNICODE);
            $logData['http_status'] = Yii::$app->response->statusCode;
            $logData['remote_ip'] = Yii::$app->request->getUserIP();
            $logData['referer'] = Yii::$app->request->referrer??'';
            $logData['useragent'] = Yii::$app->request->userAgent??'';
            $cookies = Yii::$app->request->cookies;
            $uuid = empty($cookies[PaymentRequest::CLIENT_ID_IN_COOKIE])?'':$cookies[PaymentRequest::CLIENT_ID_IN_COOKIE]->value;
            $logData['device_id'] = $uuid;
            $logData['cost_time'] = Yii::getLogger()->getElapsedTime();

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
    public static function outLog($url, $method, $logResponse, $httpStatus, $costTime = 0, $logRequest=[])
    {
        //设置了请求日志，写入日志表
        if(!empty(Yii::$app->params['apiRequestLog'])){
            $logData = Yii::$app->params['apiRequestLog'];
            $logData['request_url'] = $url;
            $logData['request_method'] = strtoupper($method)=='GET'?1:2;
            $logData['post_data'] = is_string($logRequest)?$logRequest:json_encode($logRequest);
            $logData['response_data'] = json_encode($logResponse,JSON_UNESCAPED_UNICODE);
            $logData['http_status'] = $httpStatus;
            $logData['remote_ip'] = '';
            $logData['referer'] = '';
            $logData['useragent'] = '';
            $logData['device_id'] = '';
            $logData['cost_time'] = $costTime;

            $apiRequestLog = new LogApiRequest();
            $apiRequestLog->setAttributes($logData,false);
            $apiRequestLog->save();
        }
    }
}