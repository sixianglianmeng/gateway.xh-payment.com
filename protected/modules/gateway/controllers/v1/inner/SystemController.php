<?php

namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\ChannelAccount;
use app\common\models\model\SiteConfig;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicChannelAccount;
use Yii;

/**
 * 系统接口
 */
class SystemController extends BaseInnerController
{
    /*
     * 清除缓存
     */
    public function actionClearCache()
    {
        //清除框架缓存
        Yii::$app->cache->flush();
        //清除站点配置缓存
        SiteConfig::delAllCache();

        return ResponseHelper::formatOutput(Macro::SUCCESS, '操作成功');

    }

    /*
     * 队列情况
     */
    public function actionQueueStatus()
    {
        $data = [];

        return ResponseHelper::formatOutput(Macro::SUCCESS, '操作成功');

    }
}
