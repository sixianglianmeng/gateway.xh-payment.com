<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/*
 * 商户支付配置信息
 */
class UserPaymentInfo extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%user_payment_info}}';
    }

    public function behaviors() {
        return [TimestampBehavior::className(),];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [];
    }

    public function getPaymentChannel()
    {
        return $this->hasOne(ChannelAccount::className(), ['id'=>'channel_account_id']);
    }

    /*
     * 根据appid获取支付配置信息
     * 第一期每个商户只有一个appid，app_id与merchant_id一样
     */
    public static function getByUserIdAndAppId($userId,$appId){
        $info = static::findOne(['app_id'=>$appId,'user_id'=>$userId]);

        return $info;
    }
}