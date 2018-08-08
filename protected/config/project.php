<?php
/**
 * 由于一套代码部署多个平台，某些细微差别代码到账对比更新同步代码比较麻烦
 * 故尽量使用配置项来解决平台差异，例如接口返回数据key名称等
 */

//系统名称，用于系统报警等区别
!defined('SYSTEM_NAME') && define('SYSTEM_NAME', 'gateway_xh_payment');
//redis key前缀，用于在同一个redis实例部署多套相同程序时使用
!defined('REDIS_PREFIX') && define('REDIS_PREFIX', 'gx_');

//支付方式代码
define('RECHARGE_METHOD_WEBBANK','WY');
define('RECHARGE_METHOD_WECHAT_QR','WXQR');
define('RECHARGE_METHOD_ALIPAY_QR','ALIQR');
define('RECHARGE_METHOD_QQ_QR','QQQR');
define('RECHARGE_METHOD_UNIONPAY_QR','UNQR');
define('RECHARGE_METHOD_WECHAT_H5','WXH5');
define('RECHARGE_METHOD_ALIPAY_H5','ALIH5');
define('RECHARGE_METHOD_QQ_H5','QQH5');
define('RECHARGE_METHOD_BANK_QUICK','WYKJ');
define('RECHARGE_METHOD_JD_H5','JDH5');
define('RECHARGE_METHOD_JD_QR','JDQR');
define('RECHARGE_METHOD_UNIONPAY_H5','UNH5');
define('RECHARGE_METHOD_UNIONPAY_QUICK','UNKJ');
define('RECHARGE_METHOD_WECHAT_CODEBAR','WXTM');
define('RECHARGE_METHOD_QQ_CODEBAR','QQTM');
define('RECHARGE_METHOD_ALIAPY_CODEBAR','ALITM');
define('RECHARGE_METHOD_BANK_TRANSFER','BANKTRANS');
define('RECHARGE_METHOD_ALIPAY_TRANSFER','ALITRANS');
define('RECHARGE_METHOD_WECHAT_TRANSFER','WXTRANS');

define('API_RESP_FIELD_CODE','is_success');
define('API_RESP_FIELD_DATA','data');
define('API_RESP_FIELD_MESSAGE','error_msg');
define('API_RESP_FIELD_SIGN','sign');
define('API_RESP_CODE_SUCCESS','TRUE');
define('API_RESP_CODE_FAIL','FALSE');
