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

    /*
     * 充值渠道是否支持某个支付方式
     */
//    public function hasPaymentMethod($methodId)
//    {
//        $has = strpos($this->methods,'"id":'.$methodId.',')!==false;
//        return $has;
//    }
//
//    public function getPayMethodsArr()
//    {
//        $raWmethods = empty($this->methods)?[]:json_decode($this->methods,true);
//        $methods = [];
//        foreach ($raWmethods as $m){
//            $methods[] = [
//                'id'=>$m['id'],
//                'rate'=>$m['rate'],
//                'name'=>Channel::ARR_METHOD[$m['id']]??'支付方式：'.$m['id'],
//            ];
//        }
//
//        return $methods;
//    }

    public function updatedPayMethod($method)
    {
        $raWmethods = empty($this->methods)?[]:json_decode($this->methods,true);
        foreach ($raWmethods as $k=>$m){
            if($method['id'] == $m['id']){
                $method['name'] = Channel::ARR_METHOD[$m['id']]??'支付方式：'.$m['id'];
                $raWmethods[$k] =  ArrayHelper::merge($m,$method);
            }
        }
        $this->methods = json_encode($raWmethods,JSON_UNESCAPED_UNICODE);
        $this->update();

        return $this;
    }

    public static function getALLChannelAccount()
    {
        return self::find()->select('id,channel_name')->asArray()->all();
    }
}