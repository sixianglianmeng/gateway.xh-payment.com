<?php
namespace app\modules\gateway\controllers\v1;

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
        $ips = Yii::$app->controller->merchantPayment->app_server_ips;
        if($ips){
            $ips = json_decode($ips,true);
            $currIp = Yii::$app->request->getUserIP();
            if(!empty($ips) && !in_array($currIp, $ips)){
                throw new \Exception(Macro::ERR_API_IP_DENIED);
            }
        }
        return $ret;
    }
}