<?php
namespace app\modules\gateway\controllers\v1\web;

use app\common\models\model\User;
use app\components\Macro;
use app\components\Util;
use app\components\WebAppController;
use app\jobs\PaymentNotifyJob;
use app\lib\payment\ChannelPayment;
use app\lib\payment\channels\allscore\AllScoreBasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;
use app\modules\gateway\controllers\BaseController;

/*
 * 微信后台接口
 */
class AllscoreController extends WebAppController
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
     * 异步回调
     */
    public function actionNotify()
    {
        //解析订单回调，获取统一的订单id，金额等信息
        $payment = new AllScoreBasePayment();
        $noticeResult = $payment->parseReturnRequest($this->allParams);
        Yii::debug("parseReturnRequest: ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult->order)){
            throw new \app\common\exceptions\OperationFailureException("无法解析订单信息：".$noticeResult->msg);
        }

//        if($noticeResult->status === Macro::SUCCESS){
//            LogicOrder::processChannelNotice($noticeResult);
//        }else{
//            throw new \app\common\exceptions\OperationFailureException("支付失败：".$noticeResult->msg);
//        }
        //处理订单
        LogicOrder::processChannelNotice($noticeResult);

        //获取商户回跳连接
//        $url = LogicOrder::createReturnUrl($noticeResult->order);
//        Yii::$app->response->redirect($url);

        $responseStr = AllScoreBasePayment::createdResponse(true);
        return $responseStr;
    }

    /*
     * 同步步回调
     */
    public function actionReturn()
    {
//        \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;

        //解析订单回调，获取统一的订单id，金额等信息
        $payment = new AllScoreBasePayment();
        $noticeResult = $payment->parseReturnRequest($this->allParams);
        Yii::debug("parseReturnRequest: ".\GuzzleHttp\json_encode($noticeResult));

        if(empty($noticeResult->order)){
            throw new \app\common\exceptions\OperationFailureException("无法解析订单信息：".$noticeResult->msg);
        }


        //处理订单
        LogicOrder::processChannelNotice($noticeResult);

        //获取商户回跳连接
        $url = LogicOrder::createReturnUrl($noticeResult->order);

        if ($url) {
            Yii::$app->response->redirect($url);
        } else {
            if ($noticeResult->status === Macro::SUCCESS) {
                throw new \app\common\exceptions\OperationFailureException("支付成功");
            } else {
                throw new \app\common\exceptions\OperationFailureException("支付失败：" . $noticeResult->msg);
            }
        }

    }
}
