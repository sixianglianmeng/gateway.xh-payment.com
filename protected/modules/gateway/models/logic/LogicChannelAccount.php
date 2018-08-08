<?php
namespace app\modules\gateway\models\logic;

use app\common\models\model\Channel;
use app\common\models\model\ChannelAccount;
use app\common\models\model\ChannelAccountBalanceSnap;
use app\components\Macro;
use app\components\Util;
use app\lib\payment\ChannelPayment;
use Yii;

class LogicChannelAccount
{
    /*
     * 更新三方平台账户余额
     */
    public static function syncBalance(ChannelAccount $account){

        Yii::info('check channel account balance: '.$account->channel_name);
        $paymentHandle = new ChannelPayment(null, $account);
        $ret = [];

        try{
            $ret = $paymentHandle->balance();
        }catch (\Exception $e){
            Yii::error("error to syncBalance {$account->channel_name},".$e->getMessage());
        }

        if(isset($ret['status']) && $ret['status']==Macro::SUCCESS && isset($ret['data']['balance'])){
            Yii::info('got channel account balance: '.$account->channel_name." {$ret['data']['balance']}");
            $account->balance = $ret['data']['balance'];
            if(isset($ret['data']['frozen_balance'])) $account->frozen_balance = $ret['data']['frozen_balance'];
            $account->update();

            //更新快照
            $snap = new ChannelAccountBalanceSnap();
            $snap->channel_id = $account->channel_id;
            $snap->channel_account_id = $account->id;
            $snap->channel_account_name = $account->channel_name;
            $snap->merchant_account = $account->merchant_account;
            $snap->merchant_id = $account->merchant_id;
            $snap->app_id = $account->app_id;
            $snap->balance = $account->balance?$account->balance:0;
            $snap->frozen_balance = $account->frozen_balance?$account->frozen_balance:0;
            $snap->save(false);
        }

    }

    /**
     * 检测渠道IP合法性
     *
     * @param Channel $channel 渠道类对象
     * @param string $remoteIp 客户端ip
     *
     * @return bool
     */
    public static function checkChannelIp(Channel $channel, $remoteIp='')
    {
        if(!$remoteIp) $remoteIp = Util::getClientIp();
        $serverIps = $channel->getServerIps();
        if($serverIps && $remoteIp && !in_array($remoteIp, $serverIps)){
            return false;
        }

        return true;
    }
}