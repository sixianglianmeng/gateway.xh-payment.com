<?php
namespace app\controllers;

use app\common\models\logic\LogicUser;
use app\common\models\model\Channel;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\common\models\model\User;
use app\components\Macro;
use app\components\RpcPaymentGateway;
use app\components\Util;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\channels\mf\MfBasePayment;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use function GuzzleHttp\Psr7\parse_query;
use Yii;
use power\yii2\helpers\ResponseHelper;

class SiteController extends \yii\web\Controller
{
    public function actionIndex()
    {
        exit('nginx 2.1.17');
    }

    public function actionT_df4419838b2dc89473fce6c7d19c96c7()
    {

        exit;

    }

    /**
     * This is the default 'index' action that is invoked
     * when an action is not explicitly requested by users.
     */
    public function actionError()
    {

    }
}
