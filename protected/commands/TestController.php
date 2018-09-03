<?php
namespace app\commands;
use app\common\models\model\AccountOpenFee;
use app\common\models\model\SiteConfig;
use app\common\models\model\User;
use app\components\Macro;
use app\components\RpcPaymentGateway;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\common\models\model\UserPaymentInfo;
use app\components\Util;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ChannelPayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;

class TestController extends BaseConsoleCommand
{
    public function init()
    {
        parent::init();
    }

    public function beforeAction($event)
    {
        Yii::info('console process: '.implode(' ',$_SERVER['argv']));
        return parent::beforeAction($event);
    }

    /*
     * 充值查询
     *
     * ./protected/yii test/order-status 118070717464635090
     */
    public function actionOrderStatus($no){
        $order = Order::findOne(['order_no'=>$no]);
        $ret = LogicOrder::queryChannelOrderStatus($order);
//        var_dump($ret);
    }

    /*
     * 出款提交
     *
     * ./protected/yii test/remit-commit 218082615371416661
     */
    public function actionRemitCommit($no){
        $order = Remit::findOne(['order_no'=>$no]);
        $ret = LogicRemit::commitToBank($order);
//        unset($ret['order']);
//        var_dump($ret);
    }

    /*
     * 出款查询
     *
     * ./protected/yii test/remit-status 118070717464635090
     */
    public function actionRemitStatus($no){
        $order = Remit::findOne(['order_no'=>$no]);
        $ret = LogicRemit::queryChannelRemitStatus($order);

        var_dump($ret);
    }

    /*
     * 出款通知商户
     *
     * ./protected/yii test/send-remit-notify 218082121160726579
     */
    public function actionSendRemitNotify($no){
        $order = Remit::findOne(['order_no'=>$no]);
        $ret = LogicRemit::notify($order);

        var_dump($ret);
    }


    /*
     * 余额查询
     *
     * ./protected/yii test/balance 1
     */
    public function actionBalance($id){
        $account = ChannelAccount::findOne(['id' => $id]);
        Yii::info('check channel account balance: '.$account->channel_name);
        $paymentHandle = new ChannelPayment(null, $account);

            $ret = $paymentHandle->balance();

        var_dump($ret);
    }


    /*
     * 测试
     *
     * ./protected/yii test/t
     */
    public function actionT(){
//        $uidPrefix = 201;
//        $maxPrefixId = 2013087 ;
//        if($maxPrefixId>1000){
//            $maxPrefixId = substr($maxPrefixId,3);
//        }
//            if($maxPrefixId<1000)  $maxPrefixId = mt_rand(1000,1500);
//             $id = intval($uidPrefix.$maxPrefixId)+mt_rand(10,500);
//        echo $id;

//        $s = Yii::$app->db->createCommand("SELECT id from ".User::tableName()." WHERE id=1005000")->queryScalar();
////        var_dump($s);
//        Util::sendTelegramMessage("大家好,我是劉德華",'-278804726', false);
        $t = Yii::$app->db->createCommand("SELECT id from ".User::tableName()." WHERE id=100051")->queryScalar();
        var_dump($t);
    }
}
