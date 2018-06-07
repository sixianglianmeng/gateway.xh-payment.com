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
    const METHOD_WEBBANK = 1;
    const METHOD_WECHAT_QR = 2;
    const METHOD_ALIPAY_QR = 3;
    const METHOD_QQWALLET_QR = 5;
    const METHOD_JDWALLET = 6;
    const METHOD_UNIONPAY_QR = 7;
    const METHOD_WECHAT_H5 = 10;
    const METHOD_ALIPAY_H5 = 11;
    const METHOD_QQ_H5 = 12;
    const METHOD_BANK_QUICK = 13;
    const METHOD_JD_H5 = 14;
    const METHOD_BANK_H5 = 15;
    const METHOD_WECHAT_QUICK_QR = 16;
    const METHOD_JD_QR = 17;
    const METHOD_UNIONPAY_H5 = 18;

    const ARR_METHOD = [
        self::METHOD_WEBBANK         => '网银',
        self::METHOD_WECHAT_QR       => '微信扫码',
        self::METHOD_WECHAT_QUICK_QR => '微信快捷扫码',
        self::METHOD_WECHAT_H5       => '微信H5',
        self::METHOD_ALIPAY_QR       => '支付宝扫码',
        self::METHOD_QQWALLET_QR     => 'QQ扫码',
        self::METHOD_JD_QR           => '京东扫码',
        self::METHOD_QQ_H5           => 'QQH5',
        self::METHOD_JDWALLET        => 'JD钱包',
        self::METHOD_UNIONPAY_QR     => '银联扫码',
        self::METHOD_BANK_QUICK      => '网银快捷支付',
        self::METHOD_ALIPAY_H5       => '支付宝H5',
        self::METHOD_JD_H5           => '京东H5',
        self::METHOD_UNIONPAY_H5     => '银联H5',
        self::METHOD_BANK_H5         => '网银H5',
    ];

    const ARR_METHOD_EN = [
        self::METHOD_WEBBANK         => 'webBank',
        self::METHOD_WECHAT_QR       => 'wechatQr',
        self::METHOD_WECHAT_QUICK_QR => 'wechatQuickQr',
        self::METHOD_WECHAT_H5       => 'wechatH5',
        self::METHOD_ALIPAY_QR       => 'alipayQr',
        self::METHOD_ALIPAY_H5       => 'alipayH5',
        self::METHOD_QQWALLET_QR     => 'qqQr',
        self::METHOD_QQ_H5           => 'qqH5',
        self::METHOD_JDWALLET        => 'jdWallet',
        self::METHOD_UNIONPAY_QR     => 'unoinPayQr',
        self::METHOD_BANK_QUICK      => 'bankQuickPay',
        self::METHOD_JD_H5           => 'jdH5',
        self::METHOD_JD_QR           => 'jdQr',
        self::METHOD_UNIONPAY_H5     => 'unionPayH5',
        self::METHOD_BANK_H5         => 'bankH5',
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

    public static function getPayMethodEnStr($methodId){
        return self::ARR_METHOD_EN[$methodId]??'';
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