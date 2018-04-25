<?php
namespace app\components;

use power\yii2\log\LogHelper;
use power\yii2\helpers\ResponseHelper;

/**
 * Controller is the customized base controller class.
 * All controller classes for this application should extend from this base class.
 */
class InnerController extends \power\yii2\web\Controller
{
    public function init()
    {
        parent::init();

        // 增加Ajax标识，用于异常处理
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        !defined('MODULE_NAME') && define('MODULE_NAME', SYSTEM_NAME);

        // 打印请求参数
        LogHelper::pushLog('params', $_REQUEST);
    }
    
    public function behaviors()
    {
        return [];
    }
    
    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (\Exception $e) {
            LogHelper::error($e->getMessage() . ' with code ' . $e->getCode());
            if (YII_DEBUG) {
                throw $e;
            } else {
                return ResponseHelper::outputJson([], $e->getMessage(), $e->getCode());
            }
        }
    }
}