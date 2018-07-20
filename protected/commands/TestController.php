<?php
namespace app\commands;
use app\components\Macro;
use app\components\RpcPaymentGateway;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\common\models\model\UserPaymentInfo;
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
     * ./protected/yii test/remit-commit 118070717464635090
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
//        $payment = new \app\lib\payment\ChannelPayment($order, $order->channelAccount);
//        $ret = $payment->remitStatus();

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

       echo  md5('bulnk');

        return;
        $arr = [

        ];

        foreach ($arr as $a){
            $u = "http://gateway.huaruipay.com/gateway/v1/web/mf/remit-notify?{$a}";
            echo file_get_contents($u).PHP_EOL;
        }
    }
}
