<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
/*
 * 帐变表
 */
class Financial extends BaseModel
{
    //状态未完成
    const STATUS_UNFINISHED=0;
    //状态已完成
    const STATUS_FINISHED=10;

    //帐变状态
    const EVENT_TYPE_RECHARGE = 10;
    const EVENT_TYPE_BONUS = 20;
    const EVENT_TYPE_REMIT = 30;
    const EVENT_TYPE_ADMIN = 40;
    const EVENT_TYPE_RECHARGE_FEE = 11;
    const EVENT_TYPE_REMIT_FEE = 31;
    const EVENT_TYPE_REFUND_REMIT = 51;
    const EVENT_TYPE_REFUND_REMIT_FEE = 52;

    //类型
    //收款记录,结算记录,分润记录,系统加款,系统减款,账户间转账手续费,收款手续费,结算手续费,结算失败金额返还,结算失败手续费返还,账户间转出,账户间转入,结算分润,结算失败分润退还记录
    const ARR_EVENT_TYPES = [
        self::EVENT_TYPE_RECHARGE=>'收款',
        self::EVENT_TYPE_RECHARGE_FEE=>'收款手续费',
        self::EVENT_TYPE_BONUS=>'分润',
        self::EVENT_TYPE_REMIT=>'提款',
        self::EVENT_TYPE_REMIT_FEE=>'提款手续费',
        self::EVENT_TYPE_ADMIN=>'系统调整',
        self::EVENT_TYPE_REFUND_REMIT=>'结算失败退款',
        self::EVENT_TYPE_REFUND_REMIT_FEE=>'结算失败手续费退款',
    ];

    public static function tableName()
    {
        return '{{%financial}}';
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
     * 获取订单状态描述
     *
     * @return string
     * @author chengtian.hu@gmail.com
     */
    public static function getEventTypeStr($eventType)
    {
        return self::ARR_EVENT_TYPES[$eventType]??'-';
    }

}