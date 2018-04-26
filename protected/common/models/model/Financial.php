<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
/*
 * 帐变表
 */
class Financial extends ActiveRecord
{
    //黑名单类型
    const types = [
        10=>'充值',
        11=>'充值手续费',
        20=>'分润',
        30=>'提款',
        31=>'提款手续费',
        40=>'管理员调整',
    ];
    //状态未完成
    const STATUS_UNFINISHED=0;
    //状态已完成
    const STATUS_FINISHED=10;

    //帐变状态
    const EVENT_TYPE_RECHARGE = 10;
    const EVENT_TYPE_BONUS = 20;
    const EVENT_TYPE_WITHDRAWAL = 30;
    const EVENT_TYPE_ADMIN = 40;
    const EVENT_TYPE_RECHARGE_FEE = 11;
    const EVENT_TYPE_WITHDRAWAL_FEE = 31;

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
}