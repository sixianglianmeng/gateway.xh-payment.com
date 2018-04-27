<?php
namespace app\components;
/*
 * 系统常量定义
 */
class Macro{
    const FAIL = -1;
    const SUCCESS = 0;
    const ERR_UNKNOWN = 1;
    //通用消息：操作成功
    const SUCCESS_MESSAGE = [
        'code'=>self::SUCCESS,
        'data'=>[],
        'message'=>'操作成功'
    ];

    //通用消息：操作失败
    const FAILED_MESSAGE = [
        'code'=>self::ERR_UNKNOWN,
        'data'=>[],
        'message'=>'操作失败'
    ];

    const ERR_PAGE_NOT_FOUND= 404;

    const ERR_NEED_LOGIN = 401;
    const ERR_LOGIN_FAIL = 1001;
    const ERR_NEED_SOME_PARAM = 1011;
    const ERR_PARAM_SIGN= 1012;
    const ERR_PARAM_FORMAT= 1013;

    const ERR_PERMISSION= 1021;

    const ERR_SMS_SEND_FAIL= 2021;
    const ERR_SMS_REACH_MAX= 2022;
    const ERR_SMS_VERIFY_CODE= 2023;

    const ERR_USERNAME_FORMAT = 10011;
    const ERR_USER_EXISTS = 10012;
    const ERR_USER_NOT_FOUND = 10013;
    const ERR_USER_BAN = 10014;
    const ERR_BALANCE_NOT_ENOUGH = 10015;

    const ERR_PHONE_FORMAT = 10021;
    const ERR_PHONE_EXISTS = 10022;
    const ERR_PHONE_NOT_FOUND = 10023;
    const ERR_PHONE_NEED_BIND = 10024;

    const ERR_PWD_FORMAT = 10031;
    const ERR_PWD_NOT_MATCH = 10032;
    const ERR_LOGIN_FAIL_TOO_MANY_TIMES = 10033;

    const ERR_PRODUCT_NOT_FOUND = 10041;

    const ERR_PRODUCT_ORDER_NOT_FOUND = 10051;

    const ERR_PRODUCT_STORE_NOT_FOUND = 10061;

    const ERR_ADDRESS_NOT_FOUND = 10071;

    const ERR_ORDER_NOT_FOUND = 10081;
    const ERR_REFERRER = 10082;

    const ERR_PAYMENT_CHANNEL_ACCOUNT = 10100;
    const ERR_PAYMENT_CHANNEL_ID = 10101;
    const ERR_PAYMENT_BANK_CODE = 10102;
    const ERR_PAYMENT_CALLBACK_SIGN = 10103;
    const ERR_PAYMENT_PROCESSING = 10104;
    const ERR_PAYMENT_NOTICE_RESULT_OBJECT = 10105;
    const ERR_PAYMENT_ALREADY_DONE = 10106;

    const CONST_JSON = 'json';
    const CONST_HTML = 'html';
    const CONST_PARAM_TYPE_STRING = 'string';
    const CONST_PARAM_TYPE_ARRAY = 'array';
    const CONST_PARAM_TYPE_EMAIL = 'email';
    const CONST_PARAM_TYPE_USENAME = 'username';
    const CONST_PARAM_TYPE_MOBILE = 'mobile';
    const CONST_PARAM_TYPE_TEL = 'tel';
    const CONST_PARAM_TYPE_IDCARD = 'idcard';
    const CONST_PARAM_TYPE_PASSWORD = 'password';
    const CONST_PARAM_TYPE_INT = 'int';
    const CONST_PARAM_TYPE_INT_GT_ZERO = 'int_gt_zero';
    const CONST_PARAM_TYPE_DECIMAL = 'decimal';
    const CONST_PARAM_TYPE_MD5 = 'md5';
    const CONST_PARAM_TYPE_DATE = 'date';
    const CONST_PARAM_TYPE_DATETIME = 'datetime';
    const CONST_PARAM_TYPE_NUMBER = 'number';
    const CONST_PARAM_TYPE_ORDER_NO = 'order_no';
    const CONST_PARAM_TYPE_ALNUM = 'alnum';
    const CONST_PARAM_TYPE_ENUM = 'enum';
    const CONST_PARAM_TYPE_IP = 'ip';
    const CONST_PARAM_TYPE_IPv4 = 'ipv4';
    const CONST_PARAM_TYPE_IPv6 = 'ipv6';
    const CONST_PARAM_TYPE_BANKCODE = 'bankcode';
    const CONST_PARAM_TYPE_PAYTYPE = 'paytype';
    const CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE = 'alnum_dash_underline';
    const CONST_PARAM_TYPE_DEV_PLATFORM = 'mobile_platform';

    const CONST_DEV_OS_ALL = ['android','ios','win','wp','linux'];
    const CONST_DEV_OS_ANDROID = 'android';
    const CONST_DEV_OS_IOS = 'ios';
    const CONST_DEV_OS_WIN = 'win';
    const CONST_DEV_OS_WP = 'wp';
    const CONST_DEV_OS_LINUX = 'linux';
    const CONST_DEV_TYPE_PHONE = 1;
    const CONST_DEV_TYPE_PAD = 2;
    const CONST_DEV_TYPE_LAPTOP = 3;

    const TB_PREFIX = 'p_';
    const TB_USERS = self::TB_PREFIX.'users';
    const TB_ORDERS = self::TB_PREFIX.'orders';
    const TB_FINANCIAL = self::TB_PREFIX.'financial';
    const TB_WITHDRAWAL = self::TB_PREFIX.'withdrawal';

    const MSG_LIST = [
        0 => '操作成功',
        1 => '未知错误',
        401 => '请先登录',
        1000 => '未知错误',
        1001 => '参数不完整',
        1002 => '参数校验失败',
        1003 => '用户被屏蔽',
        2021 => '短信发送失败',
        2031 => '文件上传失败',
        2023 => '短信验证码错误',

        10011 =>  '用户名错误',
        10012 =>  '用户已存在',
        10013 =>  '用户不存在',
        10014 =>  '用户被禁用',
        10021 => '电话号码错误',
        10022 => '电话号码已存在',
        10023 => '电话号码不存在',
        10024 => '需要绑定手机号',

        10031 =>  '密码格式错误',
        10032 =>  '密码错误',
        10033 =>  '登录失败次数过多，请12小时后再试。',

        10051 =>  '订单不存在',

        10061 =>  '店铺不存在',
        10071 =>  '地址不存在',

        10081 =>  '订单不存在',
    ];

    const PAY_TYPE = [
        1 => '网银支付',
        2 => '微信支付',
        3 => '支付宝支付',
        5 => 'QQ钱包',
        6 => 'JD钱包',
        7 => '银联',
    ];

    const BANK_LIST = [
        'ABC' => '中国农业银行',
        'BOC' => '中国银行',
        'BOCOM' => '交通银行',
        'CCB' => '中国建设银行',
        'ICBC' => '中国工商银行',
        'PSBC' => '中国邮政储蓄银行',
        'CMBC' => '招商银行',
        'SPDB' => '浦发银行',
        'CEBBANK' => '中国光大银行',
        'ECITIC' => '中信银行',
        'PINGAN' => '平安银行',
        'CMBCS' => '中国民生银行',
        'HXB' => '华夏银行',
        'CGB' => '广发银行',
        'BJBANK' => '北京银行',
        'BOS' => '上海银行',
        'CIB' => '兴业银行',
    ];

    public static function getAllBankCode(){
        return array_keys(BANK_LIST);
    }
}
