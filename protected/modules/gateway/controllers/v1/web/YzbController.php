<?php
namespace app\modules\gateway\controllers\v1\web;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\components\Macro;
use app\components\WebAppController;
use app\lib\payment\channels\yzb\YzbBasePayment;
use app\modules\gateway\controllers\BaseController;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;

/*
 * 汇通充值回调
 */
class YzbController extends WebAppController
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
     */
    public function actionNotify()
    {
        //解析订单回调，获取统一的订单id，金额等信息
//        $payment = new HtBasePayment();
        $payment = new YzbBasePayment();
        $noticeResult = $payment->parseReturnRequest($this->allParams);

        Yii::info("parseReturnRequest: ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult['data']['order'])){
            throw new OperationFailureException("无法解析订单信息：".$noticeResult['message']);
        }

        LogicOrder::processChannelNotice($noticeResult);
        
        $responseStr = YzbBasePayment::createdResponse(true);

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($responseStr);

        return $responseStr;
    }

    /*
     * 收款同步步回调
     */
    public function actionReturn()
    {
//        \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;

        //解析订单回调，获取统一的订单id，金额等信息
//        $payment = new HtBasePayment();
        $payment = new YzbBasePayment();
        $noticeResult = $payment->parseReturnRequest($this->allParams);
        Yii::info("parseReturnRequest: ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult->order)){
            throw new OperationFailureException("无法解析订单信息：".$noticeResult['message']);
        }

        //处理订单
        LogicOrder::processChannelNotice($noticeResult);

        //获取商户回跳连接
        $url = LogicOrder::createReturnUrl($noticeResult->order);

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
        //解析订单回调，获取统一的订单id，金额等信息
//        $payment = new HtBasePayment();
        $payment = new YzbBasePayment();
        $noticeResult = $payment->parseRemitNotifyRequest($this->allParams);

        Yii::info("parseReturnRequest: ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult['data']['remit'])){
            throw new OperationFailureException("无法解析订单信息：".$noticeResult['message']);
        }

        LogicRemit::processRemitQueryStatus($noticeResult);

        $responseStr = YzbBasePayment::createdResponse(true);

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($responseStr);

        return $responseStr;
    }
}
