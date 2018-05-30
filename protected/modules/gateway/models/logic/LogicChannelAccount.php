<?php
namespace app\modules\gateway\models\logic;

use app\components\Macro;
use Yii;
use app\common\models\model\ChannelAccount;
use app\lib\payment\ChannelPayment;

class LogicChannelAccount
{
    /*
     * 更新三方平台账户余额
     */
    public static function syncBalance($account){

        Yii::info('check channel account balance: '.$account->channel_name);
        $paymentHandle = new ChannelPayment(null, $account);
        $ret = $paymentHandle->balance();

        if(isset($ret['status']) && $ret['status']==Macro::SUCCESS && isset($ret['data']['balance'])){
            Yii::info('got channel account balance: '.$account->channel_name." {$ret['data']['balance']}");
            $account->balance = $ret['data']['balance'];
            if(isset($ret['data']['frozen_balance'])) $account->balance = $ret['data']['balance'];
            $account->update();
        }

    }
}