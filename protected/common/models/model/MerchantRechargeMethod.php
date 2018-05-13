<?php
namespace app\common\models\model;

/*
 * 商户支付方式表
 */
class MerchantRechargeMethod extends BaseModel
{
    public static function tableName()
    {
        return '{{%merchant_recharge_methods}}';
    }
}