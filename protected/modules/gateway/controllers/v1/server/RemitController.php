<?php
namespace app\modules\gateway\controllers\v1\server;

use Yii;
use app\common\exceptions\InValidRequestException;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Remit;
use app\components\Macro;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseServerSignedRequestController;
use app\modules\gateway\models\logic\LogicRemit;
use app\modules\gateway\models\logic\PaymentRequest;

/*
 * 提现代付接口
 */
class RemitController extends BaseServerSignedRequestController
{
    //响应给商户的银行状态
    const RESP_BANK_STATUS = [
        Remit::BANK_STATUS_NONE       => 'pending',
        Remit::BANK_STATUS_PROCESSING => 'processing',
        Remit::BANK_STATUS_SUCCESS    => 'success',
        Remit::BANK_STATUS_FAIL       => 'failed',
    ];

    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        //设置响应格式为商户接口json格式
        Yii::$app->response->format = Macro::FORMAT_JSON;
        Yii::$app->params['jsonFormatType'] = Macro::FORMAT_PAYMENT_GATEWAY_JSON;

        return parent::beforeAction($action);
    }

    /*
     * 单账户提现
     */
    public function actionSingle()
    {
       $needParams = ['merchant_code', 'order_no', 'order_amount', 'order_time', 'bank_code', ' account_name', 'account_number',
            'bank_province','bank_city','bank_branch','sign'];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams);

        $paymentChannelAccount = $this->merchantPayment->remitChannel;
        if(!$paymentChannelAccount){
            throw new InValidRequestException('商户提款通道配置错误，请联系平台客服');
        }
        if($paymentChannelAccount->status!=ChannelAccount::STATUS_ACTIVE && $paymentChannelAccount->status!=ChannelAccount::STATUS_RECHARGE_BANED){
            Util::throwException(Macro::ERR_REMIT_CHANNEL_NOT_ENOUGH,"出款渠道状态不正确:".$paymentChannelAccount->getStatusStr());
        }

        //兼容多平台参数
        $this->allParams['trade_no'] = $this->allParams['order_no'];
        //生成订单
        $remit = LogicRemit::addRemit($this->allParams,$this->merchant,$paymentChannelAccount);

        $msg = '代付订单提交成功';
        $data = [
            'order_no'=>$remit->merchant_order_no,
            'trade_no'=>$remit->order_no,
            'bank_status'=>self::getRespBankStatus($remit->bank_status),
        ];
        if($remit->bank_status!==Remit::BANK_STATUS_SUCCESS){
            $msg = trim($remit->fail_msg);
        }
        return ResponseHelper::formatOutput(0,$msg,$data);
    }


    /*
     * 提款状态查询
     */
    public function actionStatus()
    {
        $needParams = ['merchant_code', 'trade_no', 'order_no', 'query_time','sign'];
        $rules =     [
            'order_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
            'trade_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32], true],
        ];

        $paymentRequest = new  PaymentRequest($this->merchant, $this->merchantPayment);
        //检测参数合法性，判断用户合法性
        $paymentRequest->validate($this->allParams, $needParams, $rules);

        $msg = '代付查询成功';
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
                'order_no'=>$remit['merchant_order_no'],
                'trade_no'=>$remit['order_no'],
                'bank_status'=>self::getRespBankStatus($remit['bank_status']),
            ];
            $ret = Macro::SUCCESS;
        }else{
            $data = [
                'order_no'=>$merchantOrderNo,
                'trade_no'=>$orderNo,
                'bank_status'=>'',
            ];

            $msg = "代付记录不存在！";
        }

        return ResponseHelper::formatOutput($ret,$msg,$data);
    }

   static protected function getRespBankStatus($status){
        return self::RESP_BANK_STATUS[$status]??'error:'.$status;
    }
}
