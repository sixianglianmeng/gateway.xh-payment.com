<?php
namespace app\modules\gateway\controllers\v1\web;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\Channel;
use app\components\Macro;
use app\components\Util;
use app\components\WebAppController;
use app\lib\helpers\ControllerParameterValidator;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;

/*
 * 收款/出款订单回调处理
 */
class CallbackController extends WebAppController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        return parent::beforeAction($action);
    }

    /*
     * 收款异步回调
     *
     * int $channelId
     */
    public function actionRechargeNotify()
    {
        $channelId = ControllerParameterValidator::getRequestParam($this->allParams, 'channelId', null, Macro::CONST_PARAM_TYPE_INT, '错误的渠道参数：'.($this->allParams['channelId']??''));

        //加锁,防止三方并发回调
        $callbakcCacheKey = 'recharge_notify:'.md5(json_encode($this->allParams));
        $isProcessing = Yii::$app->cache->get($callbakcCacheKey);
        if(!$isProcessing){
            Yii::$app->cache->set($callbakcCacheKey,time(),10);
        }else{
            LogicOrder::processChannelNotice('订单处理中');
            throw new OperationFailureException("callback订单处理中: ".Util::json_encode($this->allParams));
        }

        $channel = Channel::findOne(['id'=>$channelId]);
        if(!$channel){
            throw new OperationFailureException("找不到对应渠道配置：".$channelId);
        }

        if(empty($channel->common_handle_class)){
            throw new OperationFailureException($channel->name."渠道配置错误",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$channel->common_handle_class);
        $payment =  new $handleClass();
        $channelRequestParams = $this->allParams;
        //去掉附加的channelId参数，防止渠道签名校验变量污染
        unset($channelRequestParams['channelId']);
        $noticeResult = $payment->parseNotifyRequest($channelRequestParams);

        Yii::info("parseReturnRequest: {$channel->channel_code} ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult['data']['order'])){
            throw new OperationFailureException("无法解析订单信息：".$noticeResult['message']);
        }

        LogicOrder::processChannelNotice($noticeResult);
        
        $responseStr = call_user_func([$handleClass,'createdResponse'],true);

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($responseStr);

        return $responseStr;
    }

    /*
     * 收款同步步回调
     */
    public function actionRechargeReturn()
    {
        $channelId = ControllerParameterValidator::getRequestParam($this->allParams, 'channelId', null, Macro::CONST_PARAM_TYPE_INT, '错误的渠道参数：'.($this->allParams['channelId']??''));

        $channel = Channel::findOne(['id'=>$channelId]);
        if(!$channel){
            throw new OperationFailureException("找不到对应渠道配置：".$channelId);
        }

        if(empty($channel->common_handle_class)){
            throw new OperationFailureException($channel->name."渠道配置错误",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$channel->common_handle_class);
        $payment =  new $handleClass();
        $channelRequestParams = $this->allParams;
        //去掉附加的channelId参数，防止渠道签名校验变量污染
        unset($channelRequestParams['channelId']);
        $noticeResult = $payment->parseReturnRequest($channelRequestParams);

        Yii::info("parseReturnRequest: {$channel->channel_code} ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult['data']['order'])){
            throw new OperationFailureException("无法解析订单信息：".$noticeResult['message']);
        }

        LogicOrder::processChannelNotice($noticeResult);

        //获取商户回跳连接
        $url = LogicOrder::createReturnUrl($noticeResult['data']['order']);

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog("ok: redirect:{$url}");

        if ($url) {
            Yii::$app->response->redirect($url);
        } else {
            if ($noticeResult['status'] === Macro::SUCCESS) {
                throw new OperationFailureException("支付成功");
            } else {
                throw new OperationFailureException("支付失败：" . $noticeResult['message']);
            }
        }

    }

    /*
     * 出款异步回调
     */
    public function actionRemitNotify()
    {
        $channelId = ControllerParameterValidator::getRequestParam($this->allParams, 'channelId', null, Macro::CONST_PARAM_TYPE_INT, '错误的渠道参数：'.($this->allParams['channelId']??''));

        $channel = Channel::findOne(['id'=>$channelId]);
        if(!$channel){
            throw new OperationFailureException("找不到对应渠道配置：".$channelId);
        }

        if(empty($channel->remit_handle_class)){
            throw new OperationFailureException($channel->name."渠道配置错误",Macro::ERR_PAYMENT_CHANNEL_ID);
        }

        $handleClass = "app\\lib\\payment\\channels\\".str_replace('/','\\',$channel->remit_handle_class);
        $payment =  new $handleClass();

        $noticeResult = $payment->parseRemitNotifyRequest($this->allParams);

        Yii::info("parseReturnRequest: ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult['data']['remit'])){
            throw new OperationFailureException("无法解析订单信息：".$noticeResult['message']);
        }

        LogicRemit::processRemitQueryStatus($noticeResult);

        $responseStr = call_user_func([$handleClass,'createdResponse'],true);

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($responseStr);

        return $responseStr;
    }
}
