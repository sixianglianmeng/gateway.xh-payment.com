<?php
namespace app\modules\gateway\controllers\v1\server;

use app\common\models\model\ChannelAccount;
use app\common\models\model\Remit;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseServerSignedRequestController;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicRemit;
use app\modules\gateway\models\logic\PaymentRequest;
use Yii;

/*
 * 提现代付接口
 */
class RemitController extends BaseServerSignedRequestController
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
     * 单账户提现
     */
    public function actionSingle()
    {
//        var_dump(\Yii::$app->response->format);
        //http://dev.gateway.payment.com/gateway/remit/single?merchant_code=10000&trade_no=62168809&order_amount=56.72&order_time=2018-04-27+22%3A59%3A38&account_name=%E5%BC%A0%E4%B8%89&account_number=6217002710000684874&bank_code=ABC&sign=4b961c08775b2f8e7547e491efe5125e
        $needParams = ['merchant_code', 'trade_no', 'order_amount', 'order_time', 'bank_code', ' account_name', 'account_number', 'sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);

        $paymentChannelAccount = LogicChannelAccount::getDefaultRemitChannelAccount();
        if(!$paymentChannelAccount){
            throw new InValidRequestException('提款渠道配置错误');
        }
        //生成订单
        $remit = LogicRemit::addRemit($this->allParams,$this->merchant,$paymentChannelAccount);

//        $payment = new ChannelPayment($remit,$this->merchantPayment->paymentChannel);
//        processRemit = $payment->remit();

        $remit = LogicRemit::processRemit($remit,$paymentChannelAccount);
        $msg = '';
        $data = [
            'transid'=>$remit->merchant_order_no,
            'order_id'=>$remit->order_no,
            'bank_status'=>$remit->bank_status,
        ];
        if($remit->bank_status!==Remit::BANK_STATUS_SUCCESS){
            $msg = $remit->fail_msg;
        }
        return ResponseHelper::formatOutput(0,$msg,$data);
    }


    /*
     * 提款状态查询
     */
    public function actionStatus()
    {
        $needParams = ['merchant_code', 'trade_no', 'order_no', 'now_date','sign'];
        $rules =     [
            'order_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
            'trade_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
        ];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams, $rules);

        $msg = '';
        $data = [];
        $ret = Macro::FAIL;
        $orderNo = $this->allParams['trade_no']??'';
        $merchantOrderNo = $this->allParams['order_no']??'';
        if(empty($orderNo) && empty($merchantOrderNo)){
            throw new InValidRequestException('请求参数错误');
        }

        //状态查询
        $remit = LogicRemit::getStatus($orderNo, $merchantOrderNo, $this->merchant);
        if($remit){
            $data = [
                'transid'=>$remit->merchant_order_no,
                'order_id'=>$remit->order_no,
                'bank_status'=>$remit->bank_status,
            ];
            $ret = Macro::SUCCESS;
        }

        return ResponseHelper::formatOutput($ret,$msg,$data);
    }
}
