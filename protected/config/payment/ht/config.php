<?php
/*
 * 汇通支付配置
 */
//商户号
$config['merchantId']      = '';
//md5加密密钥
$config['key']          = '';
//页面跳转同步通知页面路径
$config['return_url']   = '';
//异步通知地址
$config['notify_url']   = '';

$config['base_gateway_url']='https://api.huitongvip.com';//快捷支付PAI接口

return $config;