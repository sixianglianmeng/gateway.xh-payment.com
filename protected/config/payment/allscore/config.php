<?php
$config['merchantId']      = '001016062701807';

//安全检验码，以数字和字母组成的32位字符
$config['key']          = '22c531a0d83a46b0988ffdb038646b5b';

//页面跳转同步通知页面路径，要用 http://格式的完整路径，不允许加?id=123这类自定义参数
//return_url的域名不能写成http://localhost/allscoreutf8/return_url.php ，否则会导致return_url执行无效
//$config['return_url']   = 'http://test.allscore.com/allscoreutf8/return_url.php';
$config['return_url']   = 'http://192.168.9.33/allscore/return_url.php';

//服务器异步通知页面路径，要用 http://格式的完整路径，不允许加?id=123这类自定义参数
//$config['notify_url']   = 'http://www.xxx.com/allscoreutf8/notify_url.php';
//$config['notify_url']   = 'http://test.allscore.com/allscoreutf8/notify_url.php';
$config['notify_url']   = 'http://192.168.9.33/allscore/notify_url.php';

//↑↑↑↑↑↑↑↑↑↑请在这里配置您的基本信息↑↑↑↑↑↑↑↑↑↑↑↑↑↑↑
//字符编码格式 目前支持 utf-8
$config['input_charset']= 'UTF-8';

//访问模式,根据自己的服务器是否支持ssl访问，若支持请选择https；若不支持请选择http
$config['transport']    = 'http';

//$config['MerchantPrivateKey']=realpath(__DIR__ . '/').'/data/rsa_private_key.pem';
$config['AllscorePublicKey']=realpath(__DIR__ . '/').'/data/allscore_public_key.pem';

$config['qucik_pay_api_url']='http://58.132.206.38:8090/olgateway/';//快捷支付PAI接口
$config['request_gateway'] = 'http://58.132.206.38:8090/olgateway/serviceDirect.htm?';    //网关地址
$config['query_gateway']='http://58.132.206.38:8090/olgateway/orderQuery.htm?';   //查询网关地址
$config['bank_refund_gateway']='http://58.132.206.38:8090/olgateway/partRefund.htm?';  //纯网银退货网关地址
$config['quick_refund_gateway']='http://58.132.206.38:8090/olgateway/refund.htm?';//快捷退货地址
$config['withhold_pay_url'] = 'http://58.132.206.38:8090/olgateway/withhold/withholdPay.htm?';  //代扣支付地址
$config['withhold_query_url'] = 'http://58.132.206.38:8090/olgateway/withhold/queryOrderProtocol.htm?';  //代扣查询地址
$config['payment_url'] = 'http://58.132.206.38:8090/olgateway/agentpay/singleAgentPay.htm?';  //代付支付地址
$config['payment_query_url'] = 'http://58.132.206.38:8090/olgateway/agentpay/querySingleAgentPay.htm?';  //代付查询地址
$config['batch_payment_url'] = 'http://58.132.206.38:8090/olgateway/agentpay/batchAgentPay.htm?';  //批量代付支付地址
$config['batch_payment_query_url'] = 'http://58.132.206.38:8090/olgateway/agentpay/queryBatchAgentPay.htm?';  //批量代付查询地址
$config['balance_query_url'] = 'http://58.132.206.38:8090/olgateway/agentpay/queryMerchandBalance.htm?';  //余额查询地址


$config['auth_url'] = 'http://58.132.206.38:8090/olgateway/auth/identityCardAndName.htm?';  //身份认证地址
$config['auth_realName_url'] = 'http://58.132.206.38:8090/olgateway/auth/identityCard.htm?';  //实名认证地址
$config['auth_query_url'] = 'http://58.132.206.38:8090/olgateway/auth/authQuery.htm?';  //实名身份认证查询地址

$config['scancode_pay_url'] = 'http://58.132.206.38:8090/olgateway/scan/scanPay.htm?';  //扫码支付地址
$config['scancode_query_url'] = 'http://58.132.206.38:8090/olgateway/scan/scanPayQuery.htm?';  //扫码查询地址地址

$config['http_verify_url'] = 'http://58.132.206.38:8090:8090/olgateway/noticeQuery.htm?';  //http通知验证地址
$config['https_verify_url'] = 'https://paymenta.allscore.com/olgateway/noticeQuery.htm?';  //https通知验证地址

//$config['bankList']= require realpath(__DIR__ . '/').'/banks.php';

return $config;