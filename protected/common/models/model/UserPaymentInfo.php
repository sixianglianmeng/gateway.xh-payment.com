<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/*
 * 商户支付配置信息
 */
class UserPaymentInfo extends BaseModel
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

    /*
     * 充值渠道是否支持某个支付方式
     */
    public function hasPaymentMethod($methodId)
    {
        $has = strpos($this->methods,'"id":'.$methodId.',')!==false;
        return $has;
    }

    public function getPayMethodsArr()
    {
        $raWmethods = empty($this->pay_methods)?[]:json_decode($this->pay_methods,true);
        $methods = [];
        foreach ($raWmethods as $m){
            $methods[] = [
                'id'=>$m['id'],
                'rate'=>$m['rate'],
                'name'=>Channel::ARR_METHOD[$m['id']]??'支付方式：'.$m['id'],
            ];
        }

        return $methods;
    }

    public function getPayMethodById($id)
    {
        $raWmethods = empty($this->pay_methods)?[]:json_decode($this->pay_methods,true);
        $method = [];
        var_dump($this->user_id);
        foreach ($raWmethods as $m){
            if($id == $m['id']){
                $m['name'] = Channel::ARR_METHOD[$m['id']]??'支付方式：'.$m['id'];
                $method =  $m;
                break;
            }
        }

        return $method;
    }
}