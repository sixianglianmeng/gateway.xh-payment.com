<?php
namespace app\modules\gateway\controllers\v1;

use Yii;
use app\components\RequestSignController;
/*
 * 基础的web页面带参数校验请求，请求响应内容为web页面及跳转
 */
class BaseWebSignedRequestController extends RequestSignController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        $this->layout = 'empty';
        Yii::$app->response->format = yii\web\Response::FORMAT_HTML;
        return parent::beforeAction($action);
    }
}