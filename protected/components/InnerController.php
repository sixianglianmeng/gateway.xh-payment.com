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
            $errCode = $e->getCode();
            if($errCode === Macro::SUCCESS) $errCode = Macro::FAIL;
            if (YII_DEBUG) {
                throw $e;
//                return ResponseHelper::formatOutput($errCode, $e->getMessage());
            } else {
                $code = Macro::INTERNAL_SERVER_ERROR;
                if(property_exists($e,'statusCode')){
                    $code = $e->statusCode;
                    Yii::$app->response->statusCode=$code;
                }
                return ResponseHelper::formatOutput($errCode, $e->getMessage());
            }
        }
    }
}