<?php


namespace app\modules\gateway\models\logic;


use app\common\models\model\ChannelAccount;

class LogicChannelAccount
{
    /**
     * 获取默认的出款渠道账户配置
     *
     * @param
     * @return ChannelAccount
     */
    public static function getDefaultRemitChannelAccount()
    {
        $account = ChannelAccount::findOne(['is_default_remit'=>1]);
        return $account;
    }
}