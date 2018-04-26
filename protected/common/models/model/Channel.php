<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
/*
 * 第三方支付渠道信息
 */
class Channel extends ActiveRecord
{

    public static function tableName()
    {
        return '{{%channels}}';
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

    public function getPayMethods()
    {
        $methods = empty($this->pay_methods)?[]:json_decode($this->pay_methods,true);

        return $methods;
    }
}