<?php
namespace app\modules\gateway\controllers;

use Yii;
use app\components\Macro;

/*
 * 提现代付接口
 */
class BaseBackendApiController extends BaseController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        $ret = parent::beforeAction($action);
        Yii::$app->response->format = Macro::FORMAT_JSON;
        Yii::$app->params['jsonFormatType'] = Macro::FORMAT_PAYMENT_GATEWAY_JSON;
        return $ret;
    }
}
