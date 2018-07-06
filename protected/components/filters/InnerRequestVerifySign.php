<?php
namespace app\components\filters;

use app\common\models\model\SiteConfig;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Yii;
use yii\base\ActionFilter;

class InnerRequestVerifySign extends ActionFilter
{
    const DEFAULT_SIGN_TYPE = 'MD5';
    const SIGN_TYPES = ['MD5','RSA'];
    public $godSig;
    public $keysBlackList = ['sign'];
    
    public function beforeAction($action)
    {

        $arrQueryParams = Yii::$app->getRequest()->getQueryParams();
        $arrBodyParams  = Yii::$app->getRequest()->getBodyParams();
        $allParams   = $arrQueryParams + $arrBodyParams;

        $nonce = ControllerParameterValidator::getRequestParam($allParams, '_nonce_', null, Macro::CONST_PARAM_TYPE_STRING,"nonce错误:10-64",[10,64]);
        $sign = ControllerParameterValidator::getRequestParam($allParams, '_sign_', null, Macro::CONST_PARAM_TYPE_STRING,"sign错误:10-64",[10,64]);
        $opUserId = ControllerParameterValidator::getRequestParam($allParams, 'op_uid', null,Macro::CONST_PARAM_TYPE_INT_GT_ZERO,'操作者uid错误');
        $opUsername = ControllerParameterValidator::getRequestParam($allParams, 'op_username', null,Macro::CONST_PARAM_TYPE_USERNAME,'操作者username错误');
        $opIp = ControllerParameterValidator::getRequestParam($allParams, 'op_ip', null,Macro::CONST_PARAM_TYPE_STRING,'操作IP错误',[1,48]);

        $key = SiteConfig::cacheGetContent('gateway_rpc_key');
        $localSign = md5($key.$nonce);
        if ($localSign!==$sign) {
            throw new SignatureNotMatchException('签名错误:'.$key);
        }

        return true;
    }
}
