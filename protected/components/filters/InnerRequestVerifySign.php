<?php
namespace app\components\filters;

use app\components\Macro;
use app\components\Util;
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

        $localSign = md5(Yii::$app->params['secret']['agent.payment'].$nonce);
        if ($localSign!==$sign) {
            throw new SignatureNotMatchException('签名错误'.Yii::$app->params['secret']['agent.payment'].$nonce);
        }

        return true;
    }
}
