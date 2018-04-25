<?php
namespace app\modules\gateway\models\model;

use Yii;
use yii\db\ActiveRecord;

class Order extends ActiveRecord
{
    //0未付款，10付款中，20已支付
    const STATUS_NOTPAY=0;
    const STATUS_PAYING=10;
    const STATUS_PAID=20;
    public static function getDb()
    {
        return \Yii::$app->db;
    }

    public static function tableName()
    {
        return '{{%orders}}';
    }
}