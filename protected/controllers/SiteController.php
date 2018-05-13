<?php
namespace app\controllers;

use app\common\models\model\User;
use app\components\RpcPaymentGateway;
use Yii;
use power\yii2\helpers\ResponseHelper;

class SiteController extends \yii\web\Controller
{
    public function actionIndex()
    {
        exit('nginx 2.1.17');
    }

    public function actionT_df4419838b2dc89473fce6c7d19c96c7()
    {
        $user = User::findOne(['id'=>10005]);
        var_dump($user->balance);
        $user->updateCounters(['balance' => 10]);
        var_dump($user->balance);
        exit;
        $ret = RpcPaymentGateway::syncRechargeOrderStatus(1800);
        var_dump($ret);
    }

    /**
     * This is the default 'index' action that is invoked
     * when an action is not explicitly requested by users.
     */
    public function actionError()
    {

    }
}
