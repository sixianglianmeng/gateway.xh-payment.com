<?php
namespace app\modules\gateway\controllers\v1;

use app\components\InnerController;
use Yii;
use app\components\Macro;
use app\components\RequestSignController;

/*
 * 基础的内部服务器端带参数校验请求
 */
class BaseInnerController extends InnerController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        $ret = parent::beforeAction($action);
        Yii::$app->response->format = Macro::FORMAT_JSON;
        return $ret;
    }
}