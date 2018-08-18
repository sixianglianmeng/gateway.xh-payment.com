<?php
namespace app\modules\gateway\controllers\v1;

use app\common\exceptions\OperationFailureException;
use app\components\Util;
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

        $actionsSkipIpCheck = ['v1/server/order/order'];
        if(
            !in_array("{$this->id}/{$this->action->id}",$actionsSkipIpCheck)
            //检测IP白名单
           && !Yii::$app->controller->merchantPayment->checkAppServerIp())
        {
            throw new OperationFailureException("非法IP: ".Util::getClientIp(),Macro::ERR_API_IP_DENIED);
        }

        return $ret;
    }
}