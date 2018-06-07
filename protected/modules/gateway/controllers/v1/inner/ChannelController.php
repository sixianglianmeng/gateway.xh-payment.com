<?php

namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\ChannelAccount;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicChannelAccount;
use Yii;

/**
 * 渠道内部接口
 */
class ChannelController extends BaseInnerController
{
    /*
     * 更新三方平台账户余额
     */
    public function actionUpdateAllAccountBalance()
    {
        $lastUpdateKey = "last_chanell_account_update_ts";
        $lastUpdate    = Yii::$app->cache->get($lastUpdateKey);
        $msg           = '';
        $ret           = Macro::FAIL;
        try {
            $accounts = ChannelAccount::findAll(['status' => ChannelAccount::STATUS_ACTIVE]);
            Yii::info('find channel accounts to check balance: ' . count($accounts));
            foreach ($accounts as $account) {
                LogicChannelAccount::syncBalance($account);
            }

            $ret        = Macro::SUCCESS;
            $lastUpdate = time();
            Yii::$app->cache->set($lastUpdateKey, $lastUpdate);
        } catch (\Exception $ex) {
            $msg = "余额抓取失败:".$ex->getMessage().".\n目前查询的为" . date('Ymd H:i:s', $lastUpdate) . "更新的余额";
        }

        return ResponseHelper::formatOutput($ret, $msg, ['lastUpdate' => $lastUpdate]);

    }
}
