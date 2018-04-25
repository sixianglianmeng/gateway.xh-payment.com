<?php

namespace app\lib\payment\channels\allscore;

use Yii;
use app\common\models\model\Order;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;

class AllScoreBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 2;
    const  TRADE_STATUS_FAIL = 4;
    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
    }

    /*
     * 解析异步通知请求，返回订单
     *
     * return app\common\models\model\Order
     */
    public function parseNotifyRequest(array $request){
        //check sign

        //get order id from request
//        $orderId = $_REQUEST['orderId'];
//        //get order object and set order
//        $order = Order::findOne(['order_no'=>$orderId]);
//        $this->setOrder($order);
    }

    /*
     * 解析同步通知请求，返回订单
     * 返回订单对象表示请求验证成功且已经支付成功，可进行下一步业务
     * 返回int表示请求验证成功，订单未支付完成,int为订单在三方的状态
     * 其它表示错误
     *
     * return app\common\models\model\Order|int
     */
    public function parseReturnRequest(array $request){
        //notifyId, notifyTime, sign, outOrderId, merchantId
        $orderNo = ControllerParameterValidator::getRequestParam($request, 'outOrderId',null,Macro::CONST_PARAM_TYPE_ALNUM, '订单号错误！');
        $notifyId = ControllerParameterValidator::getRequestParam($request, 'notifyId',null,Macro::CONST_PARAM_TYPE_STRING, 'notifyId错误！',[3]);
        $notifyTime = ControllerParameterValidator::getRequestParam($request, 'notifyTime',null,Macro::CONST_PARAM_TYPE_STRING, 'notifyTime错误！',[3]);
        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);
        $merchantId = ControllerParameterValidator::getRequestParam($request, 'merchantId',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);

        $order = LogicOrder::getOrderByOrderNo($orderNo);
        $channelAccount = LogicOrder::getPaymentChannelAccount($order);
        $this->setPaymentConfig($order,$channelAccount);

        //check sign
        //计算得出通知验证结果
        require_once (Yii::getAlias("@app/lib/payment/channels/allscore/lib/allscore_notify_rsa.class.php"));

        $allscoreNotify = new \AllscoreNotify($this->paymentConfig);
        $verify_result = $allscoreNotify->verifyReturn($request);

        if($verify_result) {//验证成功
            /////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
            //请在这里加上商户的业务逻辑程序代码

            //——请根据您的业务逻辑来编写程序（以下代码仅作参考）——
            //获取商银信的通知返回参数，可参考技术文档中页面跳转同步通知参数列表
//            $out_trade_no	= $_GET['outOrderId'];	//获取订单号
//            $total_fee		= $_GET['transAmt'];		//获取总价格
//            $subject        = $_GET['subject'];
//            $body           = $_GET['body'];

            //2表示交易成功，4表示交易失败,其他状态按“处理中”处理
            if(!empty($request['tradeStatus']) && $request['tradeStatus'] == self::TRADE_STATUS_SUCCESS) {
                return $order;
            }else{
                return Macro::ERR_PAYMENT_PROCESSING;
            }
        }else{
            throw new SignatureNotMatchException("RSA签名验证失败");
        }
    }
}