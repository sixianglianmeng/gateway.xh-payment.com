<?php
namespace app\common\models\model;

use Yii;
use yii\db\ActiveRecord;

class Order extends BaseModel
{
    //-10失败 0未付款，10付款中，20已支付
    const STATUS_NOTPAY=0;
    const STATUS_PAYING=10;
    const STATUS_BLOCKED=11;
    const STATUS_PAID=20;
    const STATUS_FAIL=-10;
    const ARR_STATUS = [
        self::STATUS_NOTPAY=>'失败',
        self::STATUS_PAYING=>'未付款',
        self::STATUS_PAID=>'付款中',
        self::STATUS_BLOCKED=>'已冻结',
        self::STATUS_FAIL=>'已支付',
    ];

    const NOTICE_STATUS_NONE = 0;
    const NOTICE_STATUS_SUCCESS = 10;
    const NOTICE_STATUS_FAIL = -1;
    const ARR_NOTICE_STATUS = [
        self::NOTICE_STATUS_NONE=>'未通知',
        self::NOTICE_STATUS_SUCCESS=>'已通知',
        self::NOTICE_STATUS_FAIL=>'通知失败',
    ];

    const FINANCIAL_STATUS_NONE = 0;
    const FINANCIAL_STATUS_SUCCESS = 10;

    public static function getDb()
    {
        return \Yii::$app->db;
    }

    public static function tableName()
    {
        return '{{%orders}}';
    }


    public static function getOrderByOrderNo(string $orderNo){
        $order = Order::findOne(['order_no'=>$orderNo]);
        return $order;
    }

    public function getMerchant(){
        return $this->hasOne(User::className(), ['id'=>'merchant_id']);
    }

    /**
     * 获取订单状态描述
     *
     * @return string
     * @author chengtian.hu@gmail.com
     */
    public function getStatusStr()
    {
        return self::ARR_STATUS[$this->status]??'-';
    }

    /**
     * 获取通知状态描述
     *
     * @return string
     * @author chengtian.hu@gmail.com
     */
    public function getNotifyStatusStr()
    {
        return self::ARR_NOTICE_STATUS[$this->notify_status]??'-';
    }
}