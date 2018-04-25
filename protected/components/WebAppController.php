<?php
namespace app\components;

use Yii;
use yii\filters\Cors;
use yii\filters\RateLimiter;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBasicAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\auth\QueryParamAuth;
use yii\rest\Controller;
use power\yii2\log\LogHelper;
use power\yii2\exceptions\ParameterValidationExpandException;
use power\yii2\net\exceptions\SignatureNotMatchException;
use app\lib\helpers\ResponseHelper;
use yii\web\UnauthorizedHttpException;

/**
 * WebAppController is the customized base web app api controller class.
 * All controller classes for this application should extend from this base class.
 */
class WebAppController extends Controller
{
    public $allParams;
    public $user;

    public function init()
    {
        parent::init();

        !defined('MODULE_NAME') && define('MODULE_NAME', SYSTEM_NAME);
        LogHelper::pushLog('params', $_REQUEST);
    }

    public function beforeAction($action){
        $this->getAllParams();
        return parent::beforeAction($action);
    }

    
    public function behaviors()
    {
        $behaviors = [
            'checkCommonParameters' => [
                'class'     => \app\components\filters\CheckCommonParameters::className(),
            ],
        ];

        return $behaviors;
    }

    public function accessRules()
    {
        return \yii\helpers\ArrayHelper::merge(
            [
                'allow' => true,
                'roles' => ['?']
            ],
            parent::accessRules()
        );
    }
    
    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (ParameterValidationExpandException $e) {
            return ResponseHelper::formatOutput( Macro::PARAMETER_VALIDATION_FAILED, $e->getMessage());
        } catch (SignatureNotMatchException $e) {
            return ResponseHelper::formatOutput( Macro::SIGN_ERROR, $e->getMessage());
        } catch (UnauthorizedHttpException $e) {
            return ResponseHelper::formatOutput( $e->statusCode, $e->getMessage());
        } catch (\Exception $e) {
            LogHelper::error(
                sprintf(
                    'unkown exception occurred. %s:%s trace: %s',
                    get_class($e), 
                    $e->getMessage(),
                    str_replace("\n", " ", $e->getTraceAsString())
                )
            );
            if (YII_DEBUG) {
                throw $e;
            } else {
                $code = Macro::INTERNAL_SERVER_ERROR;
                if(property_exists($e,'statusCode')){
                    $code = $e->statusCode;
                    Yii::$app->response->statusCode=$code;
                }
                return ResponseHelper::formatOutput($code, $e->getMessage());
            }
        }
    }

    protected function getAllParams(){
        $arrQueryParams = Yii::$app->getRequest()->getQueryParams();
        $arrBodyParams  = Yii::$app->getRequest()->getBodyParams();
        $this->allParams   = $arrQueryParams + $arrBodyParams;

        return $this->allParams;
    }
}
