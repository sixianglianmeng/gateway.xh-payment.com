<?php
namespace app\components\filters;

use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\LogApiRequest;
use app\common\models\model\User;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\SignatureHelper;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Yii;
use yii\base\ActionFilter;

class VerifySign extends ActionFilter
{
    const DEFAULT_SIGN_TYPE = 'MD5';
    const SIGN_TYPES = ['MD5','RSA'];
    public $godSig;
    public $keysBlackList = ['sign'];
    
    public function beforeAction($action)
    {
        $arrQueryParams  = Yii::$app->getRequest()->getQueryParams();
        $arrBodyParams   = Yii::$app->getRequest()->getBodyParams();
        $allParams = $arrQueryParams + $arrBodyParams;

        //初始化接口日志记录
//        $method = new \ReflectionMethod(Yii::$app->controller, Yii::$app->controller->action->actionMethod);
//        preg_match("/ \* (.+)\n/", $method->getDocComment(), $comment);
//        if(!empty($comment[1])){
//            Yii::$app->params['apiRequestLog']['event_type'] = $comment[1];
//        }
        $events = [
            LogApiRequest::EVENT_TYPE_IN_RECHARGE_ADD    => 'v1/server/order/order',
            LogApiRequest::EVENT_TYPE_IN_RECHARGE_RETURN => 'v1/web/order/return',
            LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY => 'v1/web/order/notify',
            LogApiRequest::EVENT_TYPE_IN_RECHARGE_QUERY  => 'v1/server/order/status',
            LogApiRequest::EVENT_TYPE_IN_REMIT_ADD       => 'v1/server/remit/single',
            LogApiRequest::EVENT_TYPE_IN_REMIT_QUERY     => 'v1/server/remit/status',
            LogApiRequest::EVENT_TYPE_IN_REMIT_NOTIFY    => 'v1/web/remit/notify',
            LogApiRequest::EVENT_TYPE_IN_BALANCE_QUERY   => 'v1/server/account/balance',
        ];
        $distUri = Yii::$app->controller->id.'/'.Yii::$app->controller->action->id;
        if($logEventType = array_search($distUri,$events)){
            Yii::$app->params['apiRequestLog']['event_type'] = $logEventType;
        }
        Yii::$app->params['apiRequestLog']['event_id'] = $allParams['trade_no']??'';
        Yii::$app->params['apiRequestLog']['merchant_order_no'] = $allParams['order_no']??'';
        Yii::$app->params['apiRequestLog']['merchant_id'] = $allParams['merchant_code']??'';

        $strSig = ControllerParameterValidator::getRequestParam($allParams, 'sign', null, Macro::CONST_PARAM_TYPE_MD5,"签名错误");
        $merchantId = ControllerParameterValidator::getRequestParam($allParams, 'merchant_code',null,Macro::CONST_PARAM_TYPE_INT_GT_ZERO, '商户号错误');


        $merchant = User::findActive($merchantId);
        if(empty($merchant)){
            throw new SignatureNotMatchException("商户:{$merchantId}信息不存在或未激活");
        }
        Yii::$app->params['apiRequestLog']['merchant_name'] = $merchant->username;

        if(!$merchant->isMerchant()){
            throw new SignatureNotMatchException("不属于可收付款商户组:({$merchantId}-{$merchant->group_id})");
        }
        Yii::$app->params['merchant'] = $merchant;
        Yii::$app->controller->merchant = $merchant;

        //第一期每个商户只有一个appid，app_id与user_id一样
        $strSecret = $merchant->paymentInfo->app_key_md5;
        Yii::$app->params['merchantPayment'] = $merchant->paymentInfo;
        Yii::$app->controller->merchantPayment = $merchant->paymentInfo;

        $arrParams = $allParams;
        foreach ($arrParams as $strKey => $strVal) {
            if (in_array($strKey, $this->keysBlackList) == true) {
                unset($arrParams[$strKey]);
            }
        }
        $signType = self::DEFAULT_SIGN_TYPE;
        if(!empty($arrParams['signType'])){
            if(!in_array(strtoupper($arrParams['signType']),$arrParams['signType'])){
                throw new SignatureNotMatchException("签名方式不存在：{$arrParams['signType']}");
            }
            $signType = strtoupper($arrParams['signType']);
        }
        
        $strCalcSig = SignatureHelper::calcSign($arrParams, $strSecret, $signType);
        if (strcmp($strCalcSig, $strSig) !== 0) {
            throw new SignatureNotMatchException("签名错误{$strSig},{$strCalcSig}");
        }
        return true;
    }
}
