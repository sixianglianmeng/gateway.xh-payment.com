<?php
/* *
 * 商银信接口公用函数
 * 详细：该类是请求、通知返回两个文件所调用的公用函数核心处理文件
 * 版本：1.0
 * 日期：2011-11-03
 * 说明：
 * 以下代码只是为了方便商户测试而提供的样例代码，商户可以根据自己网站的需要，按照技术文档编写,并非一定要使用该代码。
 * 该代码仅供学习和研究商银信接口使用，只是提供一个参考。
 */

/**
 * 生成签名结果
 * @param $sort_para 要签名的数组
 * @param $key 商银信分配给商户的密钥
 * @param $sign_type 签名类型 默认值：MD5
 * return 签名结果字符串
 */
function buildMysign($sort_para,$key) {
	//把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
	$prestr = createLinkstring($sort_para);
	//把拼接后的字符串再与安全校验码直接连接起来
	$prestr = $prestr.$key;
	//把最终的字符串签名，获得签名结果
	logResult("验签串：".$prestr);
	$mysgin = signMD5($prestr);
	return $mysgin;
}

/**
 * 生成签名结果
 * @param $sort_para 要签名的数组
 * @param $key 商银信分配给商户的密钥
 * @param $sign_type 签名类型 默认值：RSA
 * return 签名结果字符串
 */
function buildMysignRSA($sort_para,$priKey) {
    //把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
    $prestr = createLinkstring($sort_para);
//    $prestr = 'body=body&cardAttr=01&channel=B2C&defaultBank=CMB&detailUrl=http://192.168.9.13:8080/allscore/allscore_detail.jsp&inputCharset=UTF-8&merchantId=001015013101118&notifyUrl=http://192.168.9.21:8080/allscore/allscore_notifyRSA.jsp&outOrderId=174422536212330&payMethod=bankPay&returnUrl=http://192.168.9.21:8080/allscore/allscore_returnRSA.jsp&service=directPay&subject=test&transAmt=0.01&version=1';
    //把最终的字符串签名，获得签名结果
	logResult("buildMysignRSA验签串：".$prestr);
    $mysign = signRSA($prestr,$priKey);
    //$mysign = str_replace($mysign,'+','*');
    //$mysign = str_replace($mysign,'/','-');
    return $mysign;
}

function private_decrypt($data, $prik){
    // 读取商户私钥文件
    if(!is_file($prik)){
        $pk = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($prik, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
    }
    else{
        $pk = file_get_contents($prik);
    }
    // 转换为openssl密钥，必须是没有经过pkcs8转换的私钥
    $pi_key = openssl_get_privatekey($pk);
	 logResult("解密密字段:".$data);
	openssl_private_decrypt($data,$decrypted,$pi_key);
	 logResult("解密密字段:".$decrypted);
}

/**
 * 把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
 * @param $para 需要拼接的数组
 * return 拼接完成以后的字符串
 */
function createLinkstring($para) {
	$arg  = "";
//	while (list ($key, $val) = each ($para)) {
	foreach ($para as $key=>$val){
		$arg.=$key."=".$val."&";
	}
	//去掉最后一个&字符
//	$arg = substr($arg,0,count($arg)-2);
	$arg = substr($arg,0,-1);

	//如果存在转义字符，那么去掉转义
	if(get_magic_quotes_gpc()){$arg = stripslashes($arg);}

    
	return $arg;
}

/**
 * 把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
 * @param $para 需要拼接的数组
 * return 拼接完成以后的字符串
 */
function createLinkEncode($para) {
	$arg  = "";
//	while (list ($key, $val) = each ($para)) {
	foreach ($para as $key=>$val){
		$arg.=$key."=".urlencode($val)."&";
	}
	//去掉最后一个&字符
//	$arg = substr($arg,0,count($arg)-2);
	$arg = substr($arg,0,-1);
	
	//如果存在转义字符，那么去掉转义
	if(get_magic_quotes_gpc()){$arg = stripslashes($arg);}

    
	return $arg;
}


/**
 * 除去数组中的空值和签名参数
 * @param $para 签名参数组
 * return 去掉空值与签名参数后的新签名参数组
 */
function paraFilter($para) {
	$para_filter = array();
//	while (list ($key, $val) = each ($para)) {
    foreach ($para as $key=>$val){
		if($key == "sign" || $key == "signType" || $val == "")continue;
		else	$para_filter[$key] = $para[$key];
	}
	return $para_filter;
}
/**
 * 对数组排序
 * @param $para 排序前的数组
 * return 排序后的数组
 */
function argSort($para) {
	ksort($para);
	reset($para);
	return $para;
}

/**
 * 签名字符串
 * @param $prestr 需要签名的字符串
 * @param $signType 签名类型 默认值：MD5
 * return 签名结果
 */
function signMD5($prestr) {
    $sign='';
    $sign = md5($prestr);
    return $sign;
    
    }


/**
 * 写日志，方便测试（看网站需求，也可以改成把记录存入数据库）
 * 注意：服务器需要开通fopen配置
 * @param $word 要写入日志里的文本内容 默认值：空值
 */
function logResult($word='') {
//	$fp = fopen(ALLSCORE_DIR."/log.txt","a");
//	flock($fp, LOCK_EX) ;
//	fwrite($fp,"执行日期：".strftime("%Y%m%d%H%M%S",time())."=========".$word."\n");
//	flock($fp, LOCK_UN);
//	fclose($fp);
    Yii::info($word);
}

/**
 * 远程获取数据
 * 注意：该函数的功能可以用curl来实现和代替。curl需自行编写。
 * $url 指定URL完整路径地址
 * @param $input_charset 编码格式。默认值：空值
 * @param $time_out 超时时间。默认值：60
 * return 远程输出的数据
 */
function getHttpResponse($url, $input_charset = '', $time_out = "60") {
	$urlarr     = parse_url($url);
	$errno      = "";
	$errstr     = "";
	$transports = "";
	$responseText = "";
	if($urlarr["scheme"] == "https") {
		$transports = "ssl://";
		$urlarr["port"] = "443";
	} else {
		$transports = "tcp://";
		$urlarr["port"] = "8090";
	}
	$fp=@fsockopen($transports . $urlarr['host'],$urlarr['port'],$errno,$errstr,$time_out);
	if(!$fp) {
		die("ERROR: $errno - $errstr<br />\n");
	} else {
		if (trim($input_charset) == '') {
			fputs($fp, "POST ".$urlarr["path"]." HTTP/1.1\r\n");
		}
		else {
			fputs($fp, "POST ".$urlarr["path"].'?_input_charset='.$input_charset." HTTP/1.1\r\n");
		}
		fputs($fp, "Host: ".$urlarr["host"]."\r\n");
		fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
		fputs($fp, "Content-length: ".strlen($urlarr["query"])."\r\n");
		fputs($fp, "Connection: close\r\n\r\n");
		fputs($fp, $urlarr["query"] . "\r\n\r\n");
		while(!feof($fp)) {
			$responseText .= @fgets($fp, 1024);
		}
		fclose($fp);
		$responseText = trim(stristr($responseText,"\r\n\r\n"),"\r\n");
		
		return $responseText;
	}
}
/**
 * 实现多种字符编码方式
 * @param $input 需要编码的字符串
 * @param $_output_charset 输出的编码格式
 * @param $_input_charset 输入的编码格式
 * return 编码后的字符串
 */
function charsetEncode($input,$_output_charset ,$_input_charset) {
	$output = "";
	if(!isset($_output_charset) )$_output_charset  = $_input_charset;
	if($_input_charset == $_output_charset || $input ==null ) {
		$output = $input;
	} elseif (function_exists("mb_convert_encoding")) {
		$output = mb_convert_encoding($input,$_output_charset,$_input_charset);
	} elseif(function_exists("iconv")) {
		$output = iconv($_input_charset,$_output_charset,$input);
	} else die("sorry, you have no libs support for charset change.");
	return $output;
}
/**
 * 实现多种字符解码方式
 * @param $input 需要解码的字符串
 * @param $_output_charset 输出的解码格式
 * @param $_input_charset 输入的解码格式
 * return 解码后的字符串
 */
function charsetDecode($input,$_input_charset ,$_output_charset) {
	$output = "";
	if(!isset($_input_charset) )$_input_charset  = $_input_charset ;
	if($_input_charset == $_output_charset || $input ==null ) {
		$output = $input;
	} elseif (function_exists("mb_convert_encoding")) {
		$output = mb_convert_encoding($input,$_output_charset,$_input_charset);
	} elseif(function_exists("iconv")) {
		$output = iconv($_input_charset,$_output_charset,$input);
	} else die("sorry, you have no libs support for charset changes.");
	return $output;
}



/**RSA签名
 * $data待签名数据
 * 签名用商户私钥，必须是没有经过pkcs8转换的私钥
 * 最后的签名，需要用base64编码
 * return Sign签名
 */
function signRSA($data,$priKey)
{
    // 读取商户私钥文件
    if(!is_file($priKey)){
        $priKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
    }
    else{
        $priKey = file_get_contents($priKey);
    }

	logResult("priKey=============".$priKey);
    // 转换为openssl密钥，必须是没有经过pkcs8转换的私钥
    $res = openssl_get_privatekey($priKey);
	logResult("res==============".$res);
    // 调用openssl内置签名方法，生成签名$sign
    openssl_sign($data, $sign, $res);
	//logResult("sign1==============".$sign);
    // 释放资源
    openssl_free_key($res);
    // base64编码
    $sign = base64_encode($sign);
    $sign = base64_encode($sign);
	logResult("sign2============".$sign);
    return $sign;

}

/**
 * RSA验签
 * $data待签名数据
 * $sign需要验签的签名
 * 验签用支付宝公钥
 * return 验签是否通过 bool值
 */
function verifyRSA($data, $sign,$pubKey)
{
	//logResult("111111111111111data=".print_r($data,1));
    $sign=str_replace('*','+',$sign);
    $sign=str_replace('-','/',$sign);
    //除去待签名参数数组中的空值和签名参数
    $para_filter = paraFilter($data);
    //logResult("para_filter=".print_r($para_filter,1));
    //对待签名参数数组排序
    $para_sort = argSort($para_filter);
    //logResult("para_sort=".print_r($para_sort,1));
    $data=createLinkstring($para_sort);
    // 读取商银信公钥
    $pubKey = file_get_contents($pubKey);
	//logResult("111111111111111pubKey=".$pubKey);
    // 转换为openssl格式密钥
    $res = openssl_get_publickey($pubKey);
	logResult("res=".$res);	
    // 调用openssl内置方法验签，返回bool值
    $result =  (boolean)openssl_verify($data, base64_decode($sign), $res);
	//var_dump(openssl_error_string());
	//logResult("result=".$result);	
    // 释放资源
    openssl_free_key($res);
    // 返回资源是否成功
    
    
    return $result;
    
}

/**
 * MD5验签
 * @param privateKeyStr 私钥
 * @param data 加密字符串
 * @return String 密文数据
 */
function verifySign($para_temp , $sign , $key){
	
	//除去待签名参数数组中的空值和签名参数
	$para_filter = paraFilter($para_temp);
	
	//对待签名参数数组排序
	$para_sort = argSort($para_filter);
	
	//生成签名结果
	$mysign = buildMysign($para_sort, $key);
	
	if ($mysign == $sign) {
			return true;
		} else {
			return false;
		}
 }

/**
 * 私钥加密
 * @param privateKeyStr 私钥
 * @param data 加密字符串
 * @return String 密文数据
 */
 function encryptForPrKey($privateKeyStr, $data){
	// 转换为openssl密钥，必须是没有经过pkcs8转换的私钥
	$res = openssl_pkey_get_private($privateKeyStr);
	
	if (openssl_private_encrypt($data, $encrypted, $res)) {
		//logResult("加密字段:".$data);
		$data = base64_encode($encrypted);  
		   
		//logResult("加密处理:".$data);
		}  else  {
			throw new Exception('Unable to encrypt data. Perhaps it is bigger than the key size?');  
		}
					
		return $data;  
 }
 
 /**
 * 公钥加密
 * @param publicKeyStr 公钥
 * @param data 加密字符串
 * @return String 密文数据
 */
function encryptForPuKey($pubKey, $data){
	//logResult("公钥".$pubKey);
    // 转换为openssl密钥，必须是没有经过pkcs8转换的私钥
    $res = openssl_pkey_get_public($pubKey);
	 //logResult("加密:".openssl_public_encrypt($data, $encrypted, $res));
	if (openssl_public_encrypt($data, $encrypted, $res)) {
       logResult("加密字段:".$data);
   			$data = base64_encode($encrypted);  
			   
		    logResult("加密处理:".$data);
	}  else  {
		throw new Exception('Unable to encrypt data. Perhaps it is bigger than the key size?');  
	}
            
    return $data;  
}


?>