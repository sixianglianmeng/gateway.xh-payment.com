<?php
namespace app\controllers;

use Yii;
use power\yii2\helpers\ResponseHelper;

class SiteController extends \yii\web\Controller
{
    public function actionIndex()
    {
        exit('nginx 2.1.17');
    }

    /**
     * This is the default 'index' action that is invoked
     * when an action is not explicitly requested by users.
     */
    public function actionError()
    {

    }
}
