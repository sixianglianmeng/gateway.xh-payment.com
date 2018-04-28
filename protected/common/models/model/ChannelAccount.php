<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
/*
 * 第三方支付渠道账户配置信息
 */
class ChannelAccount extends BaseModel
{

    public static function tableName()
    {
        return '{{%channel_accounts}}';
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

    public function getChannel()
    {
        return $this->hasOne(Channel::className(), ['id'=>'channel_id']);
    }

    public function getAppSectets()
    {
        return empty($this->app_secrets)?[]:json_decode($this->app_secrets,true);
    }
}