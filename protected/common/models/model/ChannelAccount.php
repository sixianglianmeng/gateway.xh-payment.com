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
//-30 充值关闭 -20 出款关闭 -10 通道维护 0正常
    const STATUS_ACTIVE=0;
    const STATUS_BANED=10;
    const STATUS_REMIT_BANED=20;
    const STATUS_RECHARGE_BANED=30;
    const ARR_STATUS = [
        self::STATUS_ACTIVE => '正常',
        self::STATUS_BANED => '通道维护',
        self::STATUS_REMIT_BANED => '出款关闭',
        self::STATUS_RECHARGE_BANED => '充值关闭',
    ];

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

    /**
     * 获取appId对应的所有支付方式数组
     *
     * @return array
     */
    public function getPayMethodsArr()
    {
        $methods = [];
        foreach ($this->payMethods as $m){
            $methods[] = [
                'id'=>$m->method_id,
                'rate'=>$m->fee_rate,
                'name'=>$m->method_name,
            ];
        }

        return $methods;
    }

    /**
     * 充值渠道账户是否支持某个支付方式
     */
    public function hasPaymentMethod($methodId)
    {
        $has = $this->getPayMethodById($methodId);
        return $has;
    }

    /**
     * 根据支付方式id获取支付方式信息
     *
     * @param int $id 支付方式id
     * @return ActiveRecord
     */
    public function getPayMethodById($id)
    {
        return $this->hasOne(ChannelAccountRechargeMethod::className(), ['channel_account_id' => 'id'])
            ->where(['method_id' => $id])->one();
    }

    /**
     * 获取支付方式列表
     *
     * @param int $id 支付方式id
     * @return array
     */
    public function getPayMethods()
    {
        return $this->hasMany(MerchantRechargeMethod::className(), ['channel_account_id' => 'id']);
    }

    public static function getALLChannelAccount()
    {
        return self::find()->select('id,channel_name')->asArray()->all();
    }
}