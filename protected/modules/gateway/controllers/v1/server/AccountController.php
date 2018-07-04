<?php
namespace app\modules\gateway\controllers\v1\server;

use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseServerSignedRequestController;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;

/*
 * 后台商户账户接口
 */
class AccountController extends BaseServerSignedRequestController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        //设置响应格式为商户接口json格式
        Yii::$app->response->format = Macro::FORMAT_JSON;
        Yii::$app->params['jsonFormatType'] = Macro::FORMAT_PAYMENT_GATEWAY_JSON;
        
        return parent::beforeAction($action);
    }

    /**
     * 账户余额查询
     */
    public function actionBalance()
    {
        $needParams = ['merchant_code', 'query_time', 'sign'];
        $rules =     [
            'query_time'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
        ];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams, $rules);

        //余额查询
        $data = [
            'money'=>bcadd($this->merchant->balance,0,6),
            'merchant_code'=>$this->merchant->id,
        ];

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->merchant->id,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_BALANCE_QUERY,
            'merchant_id'=>$this->merchant->id,
            'merchant_name'=>$this->merchant->username,
            'channel_account_id'=>Yii::$app->params['merchantPayment']->remitChannel->id,
            'channel_name'=>Yii::$app->params['merchantPayment']->remitChannel->channel_name,
        ];

        return ResponseHelper::formatOutput( Macro::SUCCESS,'余额查询成功',$data);
    }
}
