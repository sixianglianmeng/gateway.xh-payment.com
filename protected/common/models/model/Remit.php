<?php

namespace app\common\models\model;

use Yii;

/**
 * This is the model class for table "p_remit".
 *
 * @property int $id 自增ID
 * @property int $op_uid 操作者商户UID
 * @property string $op_username 操作者商户名
 * @property string $order_no 提款结算流水号
 * @property string $bat_order_no 批量提款时的批次号
 * @property string $merchant_order_no 商户提款结算流水号
 * @property string $channel_order_no 上级提款结算渠道流水号
 * @property int $merchant_id 商户ID
 * @property int $app_id 商户应用ID
 * @property int $app_name 商户应用名
 * @property string $merchant_account 商户账户
 * @property string $money 订单金额
 * @property string $withdrawal_money 实际提款结算的金额
 * @property string $channel_id 提款结算通道ID
 * @property string $channel_merchant_id 提款结算通道商户号
 * @property string $bank_account 银行帐号
 * @property string $bank_name 银行名称
 * @property string $bank_no 银行卡号
 * @property string $bank_code 银行代码
 * @property string $client_ip 订单终端用户IP
 * @property int $created_at 申请时间
 * @property int $withdrawal_at 提款结算时间
 * @property int $updated_at 记录更新时间
 * @property string $bak 订单备注
 * @property int $status 0未处理，10 已审核 20已提交到银行 30 已出款
 */
class Remit extends BaseModel
{
    //0未处理， 10 已审核 20账户已扣款 30已提交到银行 40 已出款 50处理失败已退款 -10 提交银行失败 -20 银行处理失败
    const STATUS_BANK_PROCESS_FAIL=-20;
    const STATUS_BANK_NET_FAIL=-10;
    const STATUS_NONE=0;
    const STATUS_CHECKED=10;
    const STATUS_DEDUCT=20;
    const STATUS_BANK_PROCESSING=30;
    const STATUS_SUCCESS=40;
    const STATUS_REFUND=50;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%remit}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['op_uid', 'merchant_id', 'app_id', 'app_name', 'created_at', 'withdrawal_at', 'updated_at', 'status'], 'integer'],
            [['money', 'withdrawal_money'], 'number'],
            [['op_username', 'channel_id', 'channel_merchant_id', 'bank_account', 'bank_name', 'bank_no'], 'string', 'max' => 32],
            [['order_no', 'bat_order_no', 'merchant_order_no', 'channel_order_no', 'merchant_account'], 'string', 'max' => 64],
            [['bank_code'], 'string', 'max' => 16],
            [['client_ip'], 'string', 'max' => 24],
            [['bak'], 'string', 'max' => 255],
            [['order_no'], 'unique'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'op_uid' => 'Op Uid',
            'op_username' => 'Op Username',
            'order_no' => 'Order No',
            'bat_order_no' => 'Bat Order No',
            'merchant_order_no' => 'Merchant Order No',
            'channel_order_no' => 'Channel Order No',
            'merchant_id' => 'Merchant ID',
            'app_id' => 'App ID',
            'app_name' => 'App Name',
            'merchant_account' => 'Merchant Account',
            'money' => 'Money',
            'withdrawal_money' => 'Withdrawal Money',
            'channel_id' => 'Channel ID',
            'channel_merchant_id' => 'Channel Merchant ID',
            'bank_account' => 'Bank Account',
            'bank_name' => 'Bank Name',
            'bank_no' => 'Bank No',
            'bank_code' => 'Bank Code',
            'client_ip' => 'Client Ip',
            'created_at' => 'Created At',
            'withdrawal_at' => 'Withdrawal At',
            'updated_at' => 'Updated At',
            'bak' => 'Bak',
            'status' => 'Status',
        ];
    }

    public static function getByOrderNo(string $orderNo){
        $order = Remit::findOne(['order_no'=>$orderNo]);
        return $order;
    }

    public function getMerchant(){
        return $this->hasOne(User::className(), ['id'=>'merchant_id']);
    }
}
