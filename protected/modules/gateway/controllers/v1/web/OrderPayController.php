<?php
namespace app\modules\gateway\controllers\v1\web;

use app\common\models\model\Channel;
use app\common\models\model\Order;
use app\components\Macro;
use app\components\Util;
use app\components\WebAppController;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
use app\lib\payment\ChannelPayment;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;
use app\modules\gateway\controllers\BaseController;

/*
 * 充值跳转接口
 */
class OrderPayController extends WebAppController
{
    private $_randRedirectSecretKey = "5c3865c23722f49247096d24c5de0e2a";

    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        return parent::beforeAction($action);
    }

    /*
     * 订单付款
     */
    public function actionPay()
    {
        $orderNo = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNo', null, Macro::CONST_PARAM_TYPE_ORDER_NO,'订单号错误');

        $order = Order::findOne(['order_no' => $orderNo]);
        if (!$order) {
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,'订单不存在');
        }
        //设置客户端唯一id
        PaymentRequest::setClientIdCookie();

        //更新客户端信息
        LogicOrder::updateClientInfo($order);

        //生成跳转连接
        $payment = new ChannelPayment($order, $order->channelAccount);

        $methodFnc = Channel::getPayMethodEnStr($order->pay_method_code);
        if(!is_callable([$payment,$methodFnc])){
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,"对不起,系统中此通道暂未支持此支付方式.");
        }

        //由各方法自行处理响应
        //return redirect|QrCode view|h5 call native
        $ret = $payment->$methodFnc();
        if(empty($ret['data']['type'])){
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,"无法找到支付表单渲染方式");
        }

        switch ($ret['data']['type']){
            case BasePayment::RENDER_TYPE_REDIRECT:
                if(!empty($ret['data']['formHtml'])){
                    $response = $ret['data']['formHtml'];
                }
                elseif(!empty($ret['data']['url'])){
                    $response = $this->redirect($ret['data']['url'], 302);
                }
                break;
            case BasePayment::RENDER_TYPE_QR:
                $ret['order'] = $order->toArray();
                $ret['order']['pay_method_str'] = Channel::getPayMethodsStr($order['pay_method_code']);
                $response = $this->render('@app/modules/gateway/views/cashier/qr', [
                    'data' => $ret,
                ]);
                break;
            case BasePayment::RENDER_TYPE_NATIVE:
                $ret['order'] = $order;
                $response = $this->render('cashier/native', [
                    'data' => $ret,
                ]);
                break;
            default:
                $response =  ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,"无法找到支付表单渲染方式:".$ret['data']['type']);
        }

        return $response;
    }

    /*
     * 随机跳转
     *
     * 收银台提交之后随机跳几次,然后再往上游跳转.防止商户用服务器抓取页面,获取不到用户IP.
     * 在最后一跳获取用户IP,并真正提交到上游.
     */
    public function actionRandRedirect()
    {
        $sign = ControllerParameterValidator::getRequestParam($this->allParams, 'sign', null, Macro::CONST_PARAM_TYPE_STRING,'签名错误',[10]);

        $data = Yii::$app->getSecurity()->decryptByPassword(base64_decode($sign), $this->_randRedirectSecretKey);
        $data = json_decode($data,true);
        if (empty($data['orderNo'])) {
            ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,'订单号不存在');
        }

        //还需要跳转
        if($data['leftRedirectTimes']>0){
            $data['leftRedirectTimes']--;
            return $this->redirect($this->generateRandRedirectUrl($data['orderNo'],$data['leftRedirectTimes']), 302);
        }

        return $this->redirect('/order/pay.html?orderNo='.$data['orderNo'], 302);
    }

    protected function generateRandRedirectUrl($orderNo, $leftRedirectTimes=1)
    {
        $data = [
            'orderNo'=>$orderNo,
            'leftRedirectTimes'=>$leftRedirectTimes,
        ];
        $encryptedData = Yii::$app->getSecurity()->encryptByPassword(json_encode($data), $this->_randRedirectSecretKey);
        $encryptedData = urlencode(base64_encode($encryptedData));
        return Yii::$app->request->hostInfo.'/order/go.html?sign='.$encryptedData;
    }
}
