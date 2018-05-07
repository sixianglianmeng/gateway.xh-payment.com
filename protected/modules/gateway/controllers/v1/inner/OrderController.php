<?php
namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\Order;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicOrder;
use Yii;

/*
 * 后台充值订单接口
 */
class OrderController extends BaseInnerController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        return parent::beforeAction($action);
    }

    /*
     * 订单状态查询
     */
    public function actionSyncStatus()
    {
        $inSeconds = ControllerParameterValidator::getRequestParam($this->allParams, 'inSeconds', '',Macro::CONST_PARAM_TYPE_INT_GT_ZERO,'时间秒数错误');
        $orderNoList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($inSeconds) && empty($orderNoList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'');
    }
}
