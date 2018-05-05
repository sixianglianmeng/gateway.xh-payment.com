<?php

namespace app\common\models\model;

use Yii;

/**
 * This is the model class for table "p_log_api_request".
 *
 * @property int $id 自增ID
 * @property int $merchant_id 商户ID
 * @property string $merchant_name 商户登录名
 * @property int $channel_account_id 三方账户ID
 * @property string $channel_name 三方账户名称
 * @property string $event_type 操作类型
 * @property string $event_id 操作的对象ID，如订单ID，提款ID等
 * @property string $request_url 请求地址
 * @property int $request_method 请求类型：1get 2post
 * @property string $post_data post参数
 * @property string $response_data 请求对方时的响应内容，或者我方响应给对方的内容
 * @property string $remote_ip 请求我方接口的来源IP
 * @property string $referer 请求我方接口来源页面地址
 * @property string $useragent 请求我方接口客户端信息
 * @property string $device_id 请求我方接口客户端ID
 * @property int $created_at 记录生成时间
 * @property int $updated_at 记录更新时间
 * @property int $deleted_at 记录软删除时间
 * @property string $desc 操作描述
 */
class LogApiRequest extends \yii\db\ActiveRecord
{
    const EVENT_TYPE_IN_RECHARGE_ADD = 101;
    const EVENT_TYPE_IN_RECHARGE_RETURN = 102;
    const EVENT_TYPE_IN_RECHARGE_NOTIFY = 103;
    const EVENT_TYPE_IN_RECHARGE_QUERY = 104;
    const EVENT_TYPE_IN_REMIT_ADD = 120;
    const EVENT_TYPE_IN_REMIT_QUREY = 121;
    const EVENT_TYPE_IN_BALANCE_QUREY = 130;

    const EVENT_TYPE_OUT_RECHARGE_ADD = 201;
    const EVENT_TYPE_OUT_RECHARGE_BATCH_ADD = 202;
    const EVENT_TYPE_OUT_RECHARGE_QUERY = 203;
    const EVENT_TYPE_OUT_REMIT_ADD = 220;
    const EVENT_TYPE_OUT_REMIT_QUERY = 221;
    const EVENT_TYPE_OUT_BALANCE_QUERY = 230;

    const ARR_EVENT_TYPE = [
        self::EVENT_TYPE_IN_RECHARGE_ADD => '商户充值请求',
        self::EVENT_TYPE_IN_RECHARGE_RETURN => '三方充值同步回调',
        self::EVENT_TYPE_IN_RECHARGE_NOTIFY => '三方充值异步回调',
        self::EVENT_TYPE_IN_RECHARGE_QUERY => '商户充值查询',
        self::EVENT_TYPE_IN_REMIT_ADD => '商户提款请求',
        self::EVENT_TYPE_IN_REMIT_QUREY => '商户提款查询',
        self::EVENT_TYPE_IN_BALANCE_QUREY => '商户余额查询',

        self::EVENT_TYPE_OUT_RECHARGE_ADD => '请求到三方充值',
        self::EVENT_TYPE_OUT_RECHARGE_BATCH_ADD => '请求到三方批量充值',
        self::EVENT_TYPE_OUT_RECHARGE_QUERY => '请求到三方余额查询',
        self::EVENT_TYPE_OUT_REMIT_ADD => '请求到三方提款',
        self::EVENT_TYPE_OUT_REMIT_QUERY => '请求到三方提款查询',
        self::EVENT_TYPE_OUT_BALANCE_QUERY => '请求到三方余额查询',
    ];

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'p_log_api_request';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['merchant_id', 'channel_account_id', 'request_method', 'created_at', 'updated_at', 'deleted_at'], 'integer'],
            [['request_url', 'post_data', 'response_data', 'referer', 'useragent', 'created_at', 'updated_at', 'deleted_at'], 'required'],
            [['request_url', 'post_data', 'response_data', 'referer', 'useragent'], 'string'],
            [['merchant_name', 'channel_name', 'event_id', 'remote_ip', 'device_id'], 'string', 'max' => 32],
            [['event_type'], 'string', 'max' => 64],
            [['desc'], 'string', 'max' => 512],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'merchant_id' => 'Merchant ID',
            'merchant_name' => 'Merchant Name',
            'channel_account_id' => 'Channel Account ID',
            'channel_name' => 'Channel Name',
            'event_type' => 'Event Type',
            'event_id' => 'Event ID',
            'request_url' => 'Request Url',
            'request_method' => 'Request Method',
            'post_data' => 'Post Data',
            'response_data' => 'Response Data',
            'remote_ip' => 'Remote Ip',
            'referer' => 'Referer',
            'useragent' => 'Useragent',
            'device_id' => 'Device ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'deleted_at' => 'Deleted At',
            'desc' => 'Desc',
        ];
    }
}
