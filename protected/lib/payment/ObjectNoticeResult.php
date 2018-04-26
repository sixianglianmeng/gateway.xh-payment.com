<?php

namespace app\lib\payment;

/*
 * 各三方充值通知类处理后统一返回的数据格式
 */
use app\components\Macro;

class ObjectNoticeResult
{
    //订单对象 app\common\models\model\Order
    public $order = null;
    //平台订单号
    public $orderNo = null;
    //订单实际支付金额
    public $amount = null;
    //订单状态 Macro::SUCCESS为成功，其它为失败
    public $status = Macro::ERR_UNKNOWN;
    //描述
    public $msg = 'fail';
    //三方订单流水号
    public $channelOrderNo = null;
    //三方成功时间
    public $successTime = null;
}