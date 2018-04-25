<?php

namespace app\lib\payment\channels\allscore;

use app\components\Macro;
use app\lib\payment\channels\BasePayment;
use power\yii2\net\exceptions\SignatureNotMatchException;

class AllScoreBasePayment extends BasePayment
{
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
     *
     * return app\common\models\model\Order
     */
    public function parseReturnRequest(array $request){
        $orderId = ControllerParameterValidator::getRequestParam($request, 'outOrderId',null,Macro::CONST_PARAM_TYPE_ALNUM, '订单号错误！');
        //check sign
//计算得出通知验证结果
        $allscoreNotify = new AllscoreNotify($allscore_config);
        $verify_result = $allscoreNotify->verifyReturn();
//logResult("verify_result = ".$verify_result);
        if($verify_result) {//验证成功
            /////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
            //请在这里加上商户的业务逻辑程序代码

            //——请根据您的业务逻辑来编写程序（以下代码仅作参考）——
            //获取商银信的通知返回参数，可参考技术文档中页面跳转同步通知参数列表
            $out_trade_no	= $_GET['outOrderId'];	//获取订单号
            $total_fee		= $_GET['transAmt'];		//获取总价格
            $subject        = $_GET['subject'];
            $body           = $_GET['body'];


            if($_GET['tradeStatus'] == '2') {


            }else{

            }
        }else{
            throw new SignatureNotMatchException("RSA签名验证失败");
        }
//        //get order id from request
//        $orderId = $_REQUEST['orderId'];
//        //get order object and set order
//        $order = Order::findOne(['order_no'=>$orderId]);
//        $this->setOrder($order);
    }
}