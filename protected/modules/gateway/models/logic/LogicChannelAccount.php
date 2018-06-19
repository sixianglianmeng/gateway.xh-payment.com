<?php
namespace app\modules\gateway\models\logic;

use app\common\models\model\Channel;
use app\components\Macro;
use app\components\Util;
use app\lib\payment\ChannelPayment;
use Yii;

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