<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
/*
 * 帐变表
 */
class BankCodes extends BaseModel
{


    public static function tableName()
    {
        return '{{%bank_codes}}';
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

    /**
     * 获取通道银行
     */
    public static function getBankList($channelIds)
    {
        return self::find()->select('platform_bank_code,bank_name')->where(['in','channel_id',$channelIds])->distinct()->asArray()->all();
    }

    /**
     * 获取三方银行代码
     *
     * @param int $channelId 渠道id
     * @param string $platformCode 本支付平台的银行代码
     *
     * @return string
     */
    public static function getChannelBankCode($channelId, $platformCode)
    {
        $code = self::findOne(['channel_id'=>$channelId,'platform_bank_code'=>$platformCode]);

        return $code?$code->channel_bank_code:'';
    }

}