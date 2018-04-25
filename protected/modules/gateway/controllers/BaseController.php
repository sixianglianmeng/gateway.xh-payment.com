<?php
namespace app\modules\gateway\controllers;

use Yii;
use yii\data\ActiveDataProvider;
use app\lib\helpers\ControllerParameterValidator;
use app\components\Macro;

class BaseController extends \app\components\RequestSignController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        $this->layout = 'empty';
        return parent::beforeAction($action);
    }
}