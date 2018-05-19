<?php
namespace app\modules\gateway\models\logic;

use app\common\models\model\ChannelAccount;
use app\lib\payment\ChannelPayment;

class LogicChannelAccount
{
    /*
     * 更新三方平台账户余额
     */
    public function syncBalance($account){

        Yii::info('check channel account balance: '.$account->channel_name);
        $paymentHandle = new ChannelPayment(null, $account);
        $ret = $paymentHandle->balance();

        if(isset($ret['code']) && $ret['code']==Macro::SUCCESS && isset($ret['data']['balance'])){
            Yii::info('got channel account balance: '.$account->channel_name." {$ret['data']['balance']}");
            $account->balance = $ret['data']['balance'];
            $account->update();
        }

    }
}