<?php
/* *
 * 类名：AllscoreNotify
 * 功能：商银信通知处理类
 * 详细：处理商银信接口通知返回
 * 版本：1.0
 * 日期：2011-11-03
 * 说明：
 * 以下代码只是为了方便商户测试而提供的样例代码，商户可以根据自己网站的需要，按照技术文档编写,并非一定要使用该代码。
 * 该代码仅供学习和研究商银信接口使用，只是提供一个参考

 *************************注意*************************
 * 调试通知返回时，可查看或改写log日志的写入TXT里的数据，来检查通知返回是否正常
 */

require_once(realpath(__DIR__ . '/')."/allscore_core.function.php");

class AllscoreNotify {


	var $allscore_config;

	function __construct($allscore_config){
		$this->allscore_config = $allscore_config;
	}
    function AllscoreNotify($allscore_config) {
    	$this->__construct($allscore_config);
    }
    /**
     * 针对notify_url验证消息是否是商银信发出的合法消息
     * @return 验证结果
     */
	function verifyNotify(){
		if(empty($_POST)) {//判断POST来的数组是否为空
			return false;

		}
		else {
			//生成签名结果
			$mysign = $this->getMysign($_POST);
			//获取商银信远程服务器ATN结果（验证是否是商银信发来的消息）
			
			$responseTxt = 'true';
			if (! empty($_POST["notifyId"])) {$responseTxt = $this->getResponse($_POST["notifyId"]);}
			
			//写日志记录
			$log_text = "responseTxt=".$responseTxt."\n notify_url_log:sign=".$_POST["sign"]."&mysign=".$mysign.",";
			$log_text = $log_text.createLinkString($_POST);
			logResult($log_text);
			
			//验证
			//$responsetTxt的结果不是true，与服务器设置问题、商户号、notify_id一分钟失效有关
			//mysign与sign不等，与安全校验码、请求时的参数格式（如：带自定义参数等）、编码格式有关
			if (preg_match("/true$/i",$responseTxt) && $mysign == $_POST["sign"]) {
				return true;
			} else {
				return false;
			}
		}
	}
	
    /**
     * 针对return_url验证消息是否是商银信发出的合法消息
     * @return 验证结果
     */
	function verifyReturn(){
		if(empty($_GET)) {//判断GET来的数组是否为空
			return false;
		}
		else {
			//生成签名结果
			$mysign = $this->getMysign($_GET);
			//获取商银信远程服务器ATN结果（验证是否是商银信发来的消息）
			//echo $mysign;
			
			$responseTxt = 'true';
			if (! empty($_GET["notifyId"])) {$responseTxt = $this->getResponse($_GET["notifyId"]);}
			
			//写日志记录
			$log_text = "responseTxt=".$responseTxt."\n notify_url_log:sign=".$_GET["sign"]."&mysign=".$mysign.",";
			$log_text = $log_text.createLinkString($_GET);
			logResult($log_text);
			
			//验证
			//$responsetTxt的结果不是true，与服务器设置问题、商户号、notifyId一分钟失效有关
			//mysign与sign不等，与安全校验码、请求时的参数格式（如：带自定义参数等）、编码格式有关
			if (preg_match("/true$/i",$responseTxt) && $mysign == $_GET["sign"]) {
				return true;
			} else {
				return false;
			}
		}
	}
	
    /**
     * 根据反馈回来的信息，生成签名结果
     * @param $para_temp 通知返回来的参数数组
     * @return 生成的签名结果
     */
	function getMysign($para_temp) {
		//除去待签名参数数组中的空值和签名参数
		$para_filter = paraFilter($para_temp);
		
		//对待签名参数数组排序
		$para_sort = argSort($para_filter);
		
		//生成签名结果
		$mysign = buildMysign($para_sort, trim($this->allscore_config['key']));
		
		return $mysign;
	}

    /**
     * 获取远程服务器ATN结果,验证返回URL
     * @param $nnotifyId 通知校验ID
     * @return 服务器ATN结果
     * 验证结果集：
     * invalid命令参数不对 出现这个错误，请检测返回处理中merchantId和key是否为空 
     * true 返回正确信息
     * false 请检查防火墙或者是服务器阻止端口问题以及验证时间是否超过一分钟
     */
	function getResponse($notify_id) {
		$transport = strtolower(trim($this->allscore_config['transport']));
		$merchantId = trim($this->allscore_config['merchantId']);
		$veryfy_url = '';
		if($transport == 'https') {
			$veryfy_url = $this->allscore_config['https_verify_url'];
		}
		else {
			$veryfy_url = $this->allscore_config['http_verify_url'];
		}
		$veryfy_url = $veryfy_url."merchantId=" . $merchantId . "&notifyId=" . $notify_id;
		
		$responseTxt = getHttpResponse($veryfy_url);
		  
		return $responseTxt;
	}
}
?>
