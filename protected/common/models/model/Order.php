<?php
namespace app\common\models\model;

use Yii;
use yii\db\ActiveRecord;

class Order extends ActiveRecord
{
    //-10失败 0未付款，10付款中，20已支付
    const STATUS_NOTPAY=0;
    const STATUS_PAYING=10;
    const STATUS_PAID=20;
    const STATUS_FAIL=-10;

    const NOTICE_STATUS_NONE = 0;
    const NOTICE_STATUS_SUCCESS = 10;

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

}