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
                'channel_account_name'=>$m->channel_account_name,
                'channel_account_id'=>$m->channel_account_id,
                'status' => $m->status
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

    /*
     * 更新用户的支付配置
     *
     * @param User $parentAccount 父级账户,若传值则更新对应的父级信息及利润差额
     * @param array $methods 支付配置MerchantRechargeMethod list,若传值则更新对应配置信息
     */
    public function updatePayMethods($parentAccount, $methods=[])
    {
        if(empty($methods)){
            $methods = $this->payMethods;
        }
        $parentMinRate = $parentAccount?$parentAccount->paymentInfo->payMethods:[];
        foreach ($methods as $i => $pm) {
            $pay_methods[$i]['parent_method_config_id']     = 0;
            $pay_methods[$i]['parent_recharge_rebate_rate'] = 0;
            $pay_methods[$i]['all_parent_method_config_id'] = [];
            foreach ($parentMinRate as $k => $cmr) {
                if ($pm['id'] == $cmr->method_id) {
                    //echo json_encode($pm) . json_encode($cmr->toArray()) . PHP_EOL;
                    if ($pm['rate'] < $cmr->fee_rate) {
//                        return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN, "收款渠道费率不能低于上级费率(" . Channel::ARR_METHOD[$pm['id']] . ":{$cmr->fee_rate})");
                        throw new \Exception("收款渠道费率不能低于上级费率(" . Channel::ARR_METHOD[$pm['id']] . ":{$cmr->fee_rate})");
                    }
                    //提前计算好需要给上级的分润比例
                    $allMids = [];
                    $pay_methods[$i]['parent_method_config_id']     = $cmr->id;
                    $pay_methods[$i]['parent_recharge_rebate_rate'] = bcsub($pm['rate'], $cmr->fee_rate, 9);
                    if($cmr->all_parent_method_config_id && $cmr->all_parent_method_config_id != 'null'){
                        $allMids = json_decode($cmr->all_parent_method_config_id, true);
                    }
                    //$allMids                                        = !empty($cmr->all_parent_method_config_id) ? json_decode($cmr->all_parent_method_config_id, true) : [];
                    //var_dump($allMids);
                    array_push($allMids, $cmr->id);
                    $pay_methods[$i]['all_parent_method_config_id'] = $allMids;
                }
            }
            $pay_methods[$i]['all_parent_method_config_id'] = json_encode($pay_methods[$i]['all_parent_method_config_id']);
        }

        //批量写入每种支付类型配置
        foreach ($methods as $i=>$pm){
            $methodConfig = MerchantRechargeMethod::find()->where(['method_id'=>$pm['id'],'app_id'=>$this->app_id])->limit(1)->one();
            if(!$methodConfig){
                $methodConfig = new MerchantRechargeMethod();

                $methodConfig->app_id = $this->app_id;
                $methodConfig->merchant_id = $this->user_id;
                $methodConfig->merchant_account = $this->username;

                $methodConfig->payment_info_id = $this->id;
                $methodConfig->parent_method_config_id = $pm['parent_method_config_id'];
                $methodConfig->parent_recharge_rebate_rate = $pm['parent_recharge_rebate_rate'];
                $methodConfig->all_parent_method_config_id = $pm['all_parent_method_config_id'];
            }

            $methodConfig->status = ($pm['status']==MerchantRechargeMethod::STATUS_ACTIVE)?MerchantRechargeMethod::STATUS_ACTIVE:MerchantRechargeMethod::STATUS_INACTIVE;
            $methodConfig->method_id = $pm['id'];
            $methodConfig->method_name = Channel::getPayMethodsStr($pm['id']);
            $methodConfig->fee_rate = $pm['rate'];
            $methodConfig->save();
        }
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