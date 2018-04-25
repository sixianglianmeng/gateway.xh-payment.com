<?php
/* *
 * 类名：AllscoreService
 * 功能：商银信接口构造类
 * 详细：构造商银信接口请求参数
 * 版本：1.0
 * 日期：2011-11-03
 * 说明：
 * 以下代码只是为了方便商户测试而提供的样例代码，商户可以根据自己网站的需要，按照技术文档编写,并非一定要使用该代码。
 * 该代码仅供学习和研究商银信接口使用，只是提供一个参考。
 */

require_once(realpath(__DIR__ . '/')."/allscore_submit.class.php");
require_once(realpath(__DIR__ . '/')."/allscore_core.function.php");
class AllscoreService {
	
	private $allscore_config;
	/**
	 *商银信网关地址
	 */

    

	function __construct($allscore_config){
		$this->allscore_config = $allscore_config;
	}
    function AllscoreService($allscore_config) {
		//echo $allscore_config['AllscorePublicKey'];
    	$this->__construct($allscore_config);
    }
	/**
     * 构造即时到帐接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function bankPay($para_temp) {
		
		
		//设置按钮名称
		$button_name = "商银信网银支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['request_gateway'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	/**
     * 收银台支付
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function commPay($para_temp) {
		
		
		//设置按钮名称
		$button_name = "商银信网银支付";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['qucik_pay_api_url']."serviceDirect.htm", "get", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	/**
     * 构造多卡支付接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function quickPay($para_temp) {
		
		
		//设置按钮名称
		$button_name = "商银信快捷支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['request_gateway'], "get", $button_name,$this->allscore_config);
		
		return $html_text;
	}	
	/**
     * 扫码查询
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function scanQuery($para_temp) {
		
		
		//设置按钮名称
		$button_name = "商银信扫码查询";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['scancode_query_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	
	/**
     * 代扣查询
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function withholeQuery($para_temp) {
		
		
		//设置按钮名称
		$button_name = "商银信代扣查询";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['withhold_query_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	
	/**
     * 代付查询
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function paymentQuery($para_temp) {
		
		
		
		
		//设置按钮名称
		$button_name = "商银信代付查询";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['payment_query_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	
	/**
     * 批量代付查询
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function batchPaymentQuery($para_temp) {
		//设置按钮名称
		$button_name = "商银信批量代付查询";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['batch_payment_query_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	
	/**
     * 余额查询
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function balanceQuery($para_temp) {
		//设置按钮名称
		$button_name = "商银信余额查询";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['balance_query_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	/**
     * 身份认证
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function auth($para_temp) {
		// 读取商户公钥文件
		$publicKey = file_get_contents($this->allscore_config['AllscorePublicKey']);
		
		try {
			 foreach($para_temp as $key=>$value){
				if($key == "identityCard"){
					
					$bankCardNo_m= encryptForPuKey($publicKey, $value);
					$para_temp["identityCard"] = $bankCardNo_m;
				}
				if($key == "realName"){
					
					$cardId_m= encryptForPuKey($publicKey, $value);
					$para_temp["realName"] = $cardId_m;
				 }
			}
		} catch (Exception $e) {
		   echo 'Caught exception: ',  $e->getMessage(), "\n";
		   
		}
		//设置按钮名称
		$button_name = "商银信身份认证";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['auth_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}

	/**
     * 实名认证
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function auth_realName($para_temp) {
		// 读取商户公钥文件
		$publicKey = file_get_contents($this->allscore_config['AllscorePublicKey']);
		
		try {
			 foreach($para_temp as $key=>$value){
				if($key == "identityCard"){
					
					$bankCardNo_m= encryptForPuKey($publicKey, $value);
					$para_temp["identityCard"] = $bankCardNo_m;
				}
				if($key == "realName"){
					
					$cardId_m= encryptForPuKey($publicKey, $value);
					$para_temp["realName"] = $cardId_m;
				 }
				 if($key == "dCard"){
					
					$cardId_m= encryptForPuKey($publicKey, $value);
					$para_temp["dCard"] = $cardId_m;
				 }
				 if($key == "phoneNo"){
					
					$cardId_m= encryptForPuKey($publicKey, $value);
					$para_temp["phoneNo"] = $cardId_m;
				 }
			}
		} catch (Exception $e) {
		   echo 'Caught exception: ',  $e->getMessage(), "\n";
		   
		}
		//设置按钮名称
		$button_name = "商银信身份认证";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['auth_realName_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}	
	/**
     * 实名身份认证查询
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function authQuery($para_temp) {
		//设置按钮名称
		$button_name = "商银信实名身份认证查询";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['auth_query_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	
	/**
     * 构造代扣支付接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function withholdpay($para_temp) {
		// 读取商户私钥文件
		$privateKey = file_get_contents($this->allscore_config['MerchantPrivateKey']);
		try {
			 foreach($para_temp as $key=>$value){
				if($key == "bankCardNo"){
					
					$bankCardNo_m= encryptForPrKey($privateKey, $value);
					$para_temp["bankCardNo"] = $bankCardNo_m;
				}
				if($key == "cardId"){
					
					$cardId_m= encryptForPrKey($privateKey, $value);
					$para_temp["cardId"] = $cardId_m;
				 }
				 if($key == "phoneNo"){
					
					$phoneNo_m= encryptForPrKey($privateKey, $value);
					$para_temp["phoneNo"] = $phoneNo_m;
				}
				if($key == "realName"){
					
					$realName_m= encryptForPrKey($privateKey, $value);
					$para_temp["realName"] = $realName_m;
				}
			}
		} catch (Exception $e) {
		   echo 'Caught exception: ',  $e->getMessage(), "\n";
		   
		}
		//logResult("para_temp=".print_r($para_temp,1));
		//设置按钮名称
		$button_name = "商银信代扣支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['withhold_pay_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}	
	
	/**
     * 构造批量代付支付接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function batchPayment($para_temp) {
		//logResult("para_temp=".print_r($para_temp,1));
		//设置按钮名称
		$button_name = "商银信批量代付支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['batch_payment_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	
	/**
     * 构造代付支付接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function payment($para_temp) {
		// 读取商户公钥文件
		$publicKey = file_get_contents($this->allscore_config['AllscorePublicKey']);
		
		try {
			 foreach($para_temp as $key=>$value){
				if($key == "bankCardNo"){
					
					$bankCardNo_m= encryptForPuKey($publicKey, $value);
					$para_temp["bankCardNo"] = $bankCardNo_m;
				}
				if($key == "cardId"){
					
					$cardId_m= encryptForPuKey($publicKey, $value);
					$para_temp["cardId"] = $cardId_m;
				 }
				 if($key == "phoneNo"){
					
					$phoneNo_m= encryptForPuKey($publicKey, $value);
					$para_temp["phoneNo"] = $phoneNo_m;
				}
				if($key == "realName"){
					
					$realName_m= encryptForPuKey($publicKey, $value);
					$para_temp["realName"] = $realName_m;
				}
			}
		} catch (Exception $e) {
		   echo 'Caught exception: ',  $e->getMessage(), "\n";
		   
		}
		//logResult("para_temp=".print_r($para_temp,1));
		//设置按钮名称
		$button_name = "商银信代付支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['payment_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}	

    
	
	/**
     * 构造查询接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function query($para_temp) {
		
		
		//设置按钮名称
		$button_name = "订单查询";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildAutoForm($para_temp, $this->allscore_config['query_gateway'], "get", $button_name,$this->allscore_config);
		
		return $html_text;
	}    
    
    

	/**
     * 构造提交地址
     * @param $para_temp 请求参数数组
     * @return 表单提交地址
     */
	function createBankUrl($para_temp) {				

		//生成提交地址
		$allscoreSubmit = new AllscoreSubmit();
		$ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['request_gateway'],$para_temp,$this->allscore_config);
		
		return $ItemUrl;
	}    
	
	/**
     * 构造扫码查询提交地址
     * @param $para_temp 请求参数数组
     * @return 表单提交地址
     */
	function createScanQueryUrl($para_temp) {				

		//生成提交地址
		$allscoreSubmit = new AllscoreSubmit();
		$ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['scancode_query_url'],$para_temp,$this->allscore_config);
		
		return $ItemUrl;
	} 
	
	/**
     * 构造代扣查询提交地址
     * @param $para_temp 请求参数数组
     * @return 表单提交地址
     */
	function creatWithholeQuery($para_temp) {				

		//生成提交地址
		$allscoreSubmit = new AllscoreSubmit();
		$ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['withhold_query_url'],$para_temp,$this->allscore_config);
		
		return $ItemUrl;
	} 
	
	/**
	 * 代扣相关
	 * @param $para_temp 请求参数数组
	 * @return 
	 */
	function createWithholdUrl($para_temp) {
		// 读取商户私钥文件
		$privateKey = file_get_contents($this->allscore_config['MerchantPrivateKey']);
		try {
			 foreach($para_temp as $key=>$value){
				if($key == "bankCardNo"){
					
					$bankCardNo_m= encryptForPrKey($privateKey, $value);
					$para_temp["bankCardNo"] = $bankCardNo_m;
				}
				if($key == "cardId"){
					
					$cardId_m= encryptForPrKey($privateKey, $value);
					$para_temp["cardId"] = $cardId_m;
				 }
				 if($key == "phoneNo"){
					
					$phoneNo_m= encryptForPrKey($privateKey, $value);
					$para_temp["phoneNo"] = $phoneNo_m;
				}
				if($key == "realName"){
					
					$realName_m= encryptForPrKey($privateKey, $value);
					$para_temp["realName"] = $realName_m;
				}
			}
		} catch (Exception $e) {
		   echo 'Caught exception: ',  $e->getMessage(), "\n";
		   
		}
			
		//生成提交地址
	    $allscoreSubmit = new AllscoreSubmit();
	    $ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['withhold_pay_url'],$para_temp,$this->allscore_config);
		return $ItemUrl;
	}
	
	/**
	 * 代付相关
	 * @param $para_temp 请求参数数组
	 * @return 
	 */
	function createPaymentUrl($para_temp) {
		// 读取商户公钥文件
		$publicKey = file_get_contents($this->allscore_config['AllscorePublicKey']);
		//logResult("publicKey".$publicKey);
		try {
			 foreach($para_temp as $key=>$value){
				if($key == "bankCardNo"){
					
					$bankCardNo_m= encryptForPuKey($publicKey, $value);
					$para_temp["bankCardNo"] = $bankCardNo_m;
				}
				if($key == "cardId"){
					
					$cardId_m= encryptForPuKey($publicKey, $value);
					$para_temp["cardId"] = $cardId_m;
				 }
				 if($key == "phoneNo"){
					
					$phoneNo_m= encryptForPuKey($publicKey, $value);
					$para_temp["phoneNo"] = $phoneNo_m;
				}
				if($key == "realName"){
					
					$realName_m= encryptForPuKey($publicKey, $value);
					$para_temp["realName"] = $realName_m;
				}
			}
		} catch (Exception $e) {
		   echo 'Caught exception: ',  $e->getMessage(), "\n";
		   
		}
			
		//生成提交地址
	    $allscoreSubmit = new AllscoreSubmit();
	    $ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['payment_url'],$para_temp,$this->allscore_config);
		return $ItemUrl;
	}
	
	/**
     * 构造提交地址
     * @param $para_temp 请求参数数组
     * @return 表单提交地址
     */
	function createQuickUrl($para_temp) {				

		//生成提交地址
		$allscoreSubmit = new AllscoreSubmit();
		$ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['request_gateway'],$para_temp,$this->allscore_config);
		logResult("ItemUrl=".$ItemUrl);	
		return $ItemUrl;
	}   
	/**
     * 构造扫码提交地址
     * @param $para_temp 请求参数数组
     * @return 表单提交地址
     */
	function createScankUrl($para_temp) {				

		//生成提交地址
		$allscoreSubmit = new AllscoreSubmit();
		$ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['scancode_pay_url'],$para_temp,$this->allscore_config);
		logResult("ItemUrl=".$ItemUrl);	
		return $ItemUrl;
	}   
    /**
     * 构造扫码支付地址
     * @param $para_temp 请求参数数组
     * @return 
     */
	function scanAPI($para_temp) {
		//设置按钮名称
		$button_name = "商银信网银支付";
		//生成表单提交HTML文本信息serviceDirect.htm
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['scancode_pay_url'], "post", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	}
	/**
     * 构造网银退货地址
     * @param $para_temp 请求参数数组
     * @return 退货结果
     */
	function createBankRefundUrl($para_temp) {				

		//生成提交地址
		$allscoreSubmit = new AllscoreSubmit();
		$ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['bank_refund_gateway'],$para_temp,$this->allscore_config);
		
		return $ItemUrl;
	}       
    

	/**
	 * 构造快捷退货地址
	 * @param $para_temp 请求参数数组
	 * @return 退货结果
	 */
	function createQuickRefundUrl($para_temp) {
	
	    //生成提交地址
	    $allscoreSubmit = new AllscoreSubmit();
	    $ItemUrl = $allscoreSubmit->buildRequestUrl($this->allscore_config['quick_refund_gateway'],$para_temp,$this->allscore_config);
	
	    return $ItemUrl;
	}
	
	
	

    
	
	/**
     * 构造商银信其他接口
     * @param $para_temp 请求参数数组
     * @return 表单提交HTML信息
     */
	function allscore_interface($para_temp) {
		//获取远程数据
		$allscoreSubmit = new AllscoreSubmit();
		$html_text = "";
		//请根据不同的接口特性，选择一种请求方式
		//1.构造表单提交HTML数据:（$method可赋值为get或post）
		//$allscoreSubmit->buildForm($para_temp, $this->allscore_gateway, "get", $button_name,$this->allscore_config);
		
		return $html_text;
	}
	//签约发送短信
	function fastAPIPayFastSms($para_temp){
		
		//重要字段进行加密
		//encryptForPuKey
		
		//设置按钮名称
		$button_name = "商银信网银API快捷支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
			if (trim($para_temp['realName'])!=""){
				$realName = $allscoreSubmit->encryptForPuKey($para_temp['realName'],trim($this->allscore_config['AllscorePublicKey']));
				$para_temp['realName']=$realName;
			}
			
	
			if (trim($para_temp['bankCardNo'])!=""){
				$bankCardNo = $allscoreSubmit->encryptForPuKey($para_temp['bankCardNo'],trim($this->allscore_config['AllscorePublicKey']));
				$para_temp['bankCardNo']=$bankCardNo;
			}
	
	
			if (trim($para_temp['cardId'])!=""){
				$cardId = $allscoreSubmit->encryptForPuKey($para_temp['cardId'],trim($this->allscore_config['AllscorePublicKey']));
				$para_temp['cardId']=$cardId;
			}
		
			if (trim($para_temp['phoneNo'])!=""){
				$phoneNo = $allscoreSubmit->encryptForPuKey($para_temp['phoneNo'],trim($this->allscore_config['AllscorePublicKey']));
				$para_temp['phoneNo']=$phoneNo;
			}
	
			
	
			if (trim($para_temp['expireMonth'])!=""){
					$expireMonth = $allscoreSubmit->encryptForPuKey($para_temp['expireMonth'],trim($this->allscore_config['AllscorePublicKey']));
					$para_temp['expireMonth']=$expireMonth;
			}
		

			if (trim($para_temp['expireYear'])!=""){
					$expireYear = $allscoreSubmit->encryptForPuKey($para_temp['expireYear'],trim($this->allscore_config['AllscorePublicKey']));
					$para_temp['expireYear']=$expireYear;
			}
			

			if (trim($para_temp['cvv'])!=""){
					$cvv = $allscoreSubmit->encryptForPuKey($para_temp['cvv'],trim($this->allscore_config['AllscorePublicKey']));
					$para_temp['cvv']=$cvv;
			}
			
	
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['qucik_pay_api_url']."quickDirect/fastSms.htm", "post", $button_name,$this->allscore_config);
		return $html_text;
	}
	//签约号支付
	function fastAPIfastBindPay($para_temp){
			//设置按钮名称
		$button_name = "商银信网银API快捷支付";
		//生成表单提交HTML文本信息
		$allscoreSubmit = new AllscoreSubmit();
			$agreeNo = $allscoreSubmit->encryptForPuKey($para_temp['agreeNo'],trim($this->allscore_config['AllscorePublicKey']));
			$para_temp['agreeNo']=$agreeNo;
		$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['qucik_pay_api_url']."quickDirect/fastBindPay.htm", "post", $button_name,$this->allscore_config);
		return $html_text;

	}
	function fastAPIPayfastPay($para_temp){
		$button_name = "商银信网银API快捷支付";
			//生成表单提交HTML文本信息
				$allscoreSubmit = new AllscoreSubmit();
				$verifyCode = $allscoreSubmit->encryptForPuKey($para_temp['verifyCode'],trim($this->allscore_config['AllscorePublicKey']));
				$para_temp['verifyCode']=$verifyCode;
			$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['qucik_pay_api_url']."/quickDirect/fastPay.htm", "post", $button_name,$this->allscore_config);
			return $html_text;
	}
	function fastAPIPayfastReSms($para_temp){
			$button_name = "商银信网银API快捷支付";
			//生成表单提交HTML文本信息
				$allscoreSubmit = new AllscoreSubmit();
			$html_text = $allscoreSubmit->buildForm($para_temp, $this->allscore_config['qucik_pay_api_url']."/quickDirect/fastReSms.htm", "post", $button_name,$this->allscore_config);
			return $html_text;
	}

?>