<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
/*
 * 第三方支付渠道信息
 */
class Channel extends BaseModel
{
    const METHOD_WEBBANK=1;
    const METHOD_WECHAT=2;
    const METHOD_ALIPAY=3;
    const METHOD_QQWALLET=5;
    const METHOD_JDWALLET=6;
    const METHOD_UNIONPAY=7;
    const METHOD_WECHAT_H5 =10;
    const METHOD_ALIPAY_H5 =11;
    const METHOD_QQ_H5 = 12;
    const METHOD_QUICK = 13;


    const ARR_METHOD = [
        self::METHOD_WEBBANK => '网银',
        self::METHOD_WECHAT => '微信扫码',
        self::METHOD_WECHAT_H5 => '微信H5',
        self::METHOD_ALIPAY => '支付宝扫码',
        self::METHOD_QQWALLET => 'QQ扫码',
        self::METHOD_QQ_H5 => 'QQH5',
        self::METHOD_JDWALLET => 'JD钱包',
        self::METHOD_UNIONPAY => '银联扫码',
        self::METHOD_QUICK => '快捷支付',
        self::METHOD_ALIPAY_H5 => '支付宝H5',
    ];

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

    /*
     * 充值渠道是否支持某个支付方式
     */
    public function hasPaymentMethod($methodId)
    {
        $has = strpos($this->methods,'"id":'.$methodId.',')!==false;
        return $has;
    }

    public static function getPayMethodsStr($methodId){
        return self::ARR_METHOD[$methodId]??'';
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

    public static function getALLChannel()
    {
        return self::find()->select('id,name')->asArray()->all();
    }
}