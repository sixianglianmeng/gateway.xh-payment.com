<?php
namespace app\modules\gateway\controllers;

use app\common\models\model\User;
use app\components\Macro;
use app\components\Util;
use app\components\WebAppController;
use app\lib\payment\ChannelPayment;
use app\lib\payment\channels\allscore\AllScoreBasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;
use app\modules\gateway\controllers\BaseController;
use app\lib\payment\ObjectNoticeResult;

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

    }

    /*
     * 同步步回调
     */
    public function actionReturn()
    {
        $payment = new AllScoreBasePayment();

        $noticeResult = $payment->parseReturnRequest($this->allParams);
        if($noticeResult->status === Macro::SUCCESS){

        }

    }
}
