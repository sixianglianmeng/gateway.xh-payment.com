<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;

/*
 * 商户支付配置信息
 */
class UserPaymentInfo extends BaseModel
{
    const ALLOW_API_RECHARGE_NO=0;
    const ALLOW_API_RECHARGE_YES=1;
    const ALLOW_API_REMIT_NO=0;
    const ALLOW_API_REMIT_YES=1;
    const ALLOW_MANUAL_RECHARGE_NO=0;
    const ALLOW_MANUAL_RECHARGE_YES=1;
    const ALLOW_MANUAL_REMIT_NO=0;
    const ALLOW_MANUAL_REMIT_YES=1;
    const ALLOW_API_FAST_REMIT_YES=1;
    const ALLOW_API_FAST_REMIT_NO=1;

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

//    public function getPaymentChannel()
//    {
//        return $this->hasOne(ChannelAccount::className(), ['id'=>'channel_account_id']);
//    }

    public function getRemitChannel()
    {
        return $this->hasOne(ChannelAccount::className(), ['id'=>'remit_channel_account_id']);
    }

    /**
     * 获取appId对应的所有支付方式数组
     *
     * @return array
     */
    public function getPayMethodsArr()
    {
        $raWmethods = $this->getPayMethods();
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
     * 根据appId获取对应的支付方式列表，目前appId=merchantId
     *
     * @return string
     */
    public static function getPayMethodsArrByAppId($appId)
    {
        $raWmethods = MerchantRechargeMethod::findAll(['app_id'=>$appId]);
        $methods = [];
        foreach ($raWmethods as $m){
            $methods[] = [
                'id'=>$m->method_id,
                'rate'=>$m->fee_rate,
                'name'=>$m->method_name,
            ];
        }

        return $methods;
    }

    /**
     * 充值渠道是否支持某个支付方式
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
        return $this->hasOne(MerchantRechargeMethod::className(), ['payment_info_id' => 'id'])
            ->where(['method_id' => $id])->one();
    }

    /**
     * 支付方式列表
     *
     * @param int $id 支付方式id
     * @return array
     */
    public function getPayMethods()
    {
        return $this->hasMany(MerchantRechargeMethod::className(), ['payment_info_id' => 'id']);
    }

    public function updatedPayMethod($method)
    {
        $raWmethods = empty($this->pay_methods)?[]:json_decode($this->pay_methods,true);
        foreach ($raWmethods as $k=>$m){
            if($method['id'] == $m['id']){
                $method['name'] = Channel::ARR_METHOD[$m['id']]??'支付方式：'.$m['id'];
                $raWmethods[$k] =  ArrayHelper::merge($m,$method);
            }
        }
        $this->pay_methods = json_encode($raWmethods,JSON_UNESCAPED_UNICODE);
        $this->update();

        return $this;
    }

    /**
     * 获取所有上级支付方式配置
     *
     * @return array|mixed
     */
    public function getAllParentRemitChannelAccount()
    {
        $pids = $this->getAllParentAgentId();
        return self::find(['id'=>$pids])->all();
    }

    /**
     * 获取某支付方式所有上级支付方式配置
     *
     * @return array|mixed
     */
    public function getMethodAllParentAgentConfig($pids)
    {
        $pids = $this->getAllParentAgentId();
        return UserPaymentInfo::findAll(['app_id'=>$pids,'method_id'=>$mid]);
    }
}