<?php
namespace app\components\filters;

use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use Yii;
use app\lib\helpers\SignatureHelper;
use power\yii2\net\exceptions\SignatureNotMatchException;
use power\yii2\helpers\ParameterValidatorHelper;
use yii\base\ActionFilter;
use app\common\models\model\User;

class VerifySign extends ActionFilter
{
    const DEFAULT_SIGN_TYPE = 'MD5';
    const SIGN_TYPES = ['MD5','RSA'];
    public $godSig;
    public $keysBlackList = ['sign'];
    
    public function beforeAction($action)
    {
        $strSig = ControllerParameterValidator::getRequestParam($_REQUEST, 'sign', null, Macro::CONST_PARAM_TYPE_MD5,"签名错误");
        $merchantId = ControllerParameterValidator::getRequestParam($_REQUEST, 'merchant_code',null,Macro::CONST_PARAM_TYPE_INT_GT_ZERO, '商户号错误');

        $merchant = User::findActive($merchantId);
        if(empty($merchant)){
            throw new SignatureNotMatchException("商户信息不存在或未激活");
        }
        Yii::$app->params['merchant'] = $merchant;
        Yii::$app->controller->merchant = $merchant;

        //第一期每个商户只有一个appid，app_id与user_id一样
        $merchantPayment = UserPaymentInfo::getByUserIdAndAppId($merchant->id,$merchantId);
        $strSecret = $merchantPayment->app_key_md5;
        Yii::$app->params['merchantPayment'] = $merchantPayment;
        Yii::$app->controller->merchantPayment = $merchantPayment;

        // qa、test环境万能签名.
        if (defined('APPLICATION_ENV') && 
            in_array(APPLICATION_ENV, ['dev', 'test']) == true
        ) {
            if ($this->godSig && $strSig === $this->godSig) {
                return true;
            }
        }

        $arrParams = array_merge($_POST, $_GET);
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
        
        $strCalcSig = SignatureHelper::calcSign($arrParams, $strSecret,$signType);
        if (strcmp($strCalcSig, $strSig) !== 0) {
            throw new SignatureNotMatchException("签名错误{$strSig},{$strCalcSig}");
        }
        return true;
    }
}
