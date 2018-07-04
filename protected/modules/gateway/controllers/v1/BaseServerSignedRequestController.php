<?php
namespace app\modules\gateway\controllers\v1;

use app\common\exceptions\OperationFailureException;
use Yii;
use app\components\Macro;
use app\components\RequestSignController;

/*
 * 基础的服务器端带参数校验请求,请求响应内容为Macro::FORMAT_PAYMENT_GATEWAY_JSON格式
 */
class BaseServerSignedRequestController extends RequestSignController
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

        //检测IP白名单
        if(!Yii::$app->controller->merchantPayment->checkAppServerIp()){
            throw new OperationFailureException(Macro::ERR_API_IP_DENIED);
        }

        return $ret;
    }
}