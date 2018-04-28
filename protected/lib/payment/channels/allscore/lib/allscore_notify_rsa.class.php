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

require_once(realpath(__DIR__ . '/') . "/allscore_core.function.php");

class AllscoreNotify
{


    var $allscore_config;

    function __construct($allscore_config)
    {
        $this->allscore_config = $allscore_config;
    }

    function AllscoreNotify($allscore_config)
    {
        $this->__construct($allscore_config);
    }

    /**
     * 针对notify_url验证消息是否是商银信发出的合法消息
     * @return 验证结果
     */
    function verifyNotify()
    {
        if (empty($_POST)) {//判断POST来的数组是否为空

            return false;

        } else {

            $sign = $_POST["sign"];

            //生成签名结果
            $isSign = verifyRSA($_POST, $sign, trim($this->allscore_config['AllscorePublicKey']));
            //获取商银信远程服务器ATN结果（验证是否是商银信发来的消息）

            $responseTxt = 'true';
            if (!empty($_POST["notifyId"])) {
                $responseTxt = $this->getResponse($_POST["notifyId"]);
            }

            //写日志记录
            $log_text = "responseTxt=" . $responseTxt . "\n notify_url_log:sign=" . $_POST["sign"] . "&isSign=" . $isSign . ",";
            $log_text = $log_text . createLinkString($_POST);
            logResult($log_text);

            //验证
            //$responsetTxt的结果不是true，与服务器设置问题、商户号、notify_id一分钟失效有关
            if (preg_match("/true$/i", $responseTxt) && $isSign) {
                //logResult("1111111111111111111112222222222222");
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
    function verifyReturn($request)
    {
        if (empty($request)) {//判断GET来的数组是否为空
            return false;
        } else {

            $sign = $request["sign"];
            logResult("2222222222222222222222222222222222222222222222222isSign=" . $request["sign"]);

            //生成签名结果
            $isSign = verifyRSA($request, $sign, trim($this->allscore_config['AllscorePublicKey']));
            //获取商银信远程服务器ATN结果（验证是否是商银信发来的消息）
            //echo $mysign;
            //logResult("2222222222222222222222222222222222222222222222222isSign=".$isSign);
            $responseTxt = 'true';
            if (!empty($request["notifyId"])) {
                $responseTxt = $this->getResponse($request["notifyId"]);
            }

            //写日志记录
            $log_text = "responseTxt=" . $responseTxt . "\n notify_url_rsa_log:sign=" . $request["sign"] . "&isSign=" . $isSign . ",";
            $log_text = $log_text . createLinkString($request);
            logResult($log_text);

            //验证
            //$responsetTxt的结果不是true，与服务器设置问题、商户号、notifyId一分钟失效有关
            if (preg_match("/true$/i", $responseTxt) && $isSign) {
                //logResult("5555555555555555555555555555555555555555");
                return true;
            } else {
                return false;
            }
        }
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
    function getResponse($notify_id)
    {
        $transport  = strtolower(trim($this->allscore_config['transport']));
        $merchantId = trim($this->allscore_config['merchantId']);
        $veryfy_url = '';
        if ($transport == 'https') {
            $veryfy_url = $this->allscore_config['https_verify_url'];
        } else {
            $veryfy_url = $this->allscore_config['http_verify_url'];
        }
        $veryfy_url = $veryfy_url . "merchantId=" . $merchantId . "&notifyId=" . $notify_id;

        $responseTxt = getHttpResponse($veryfy_url);

        return $responseTxt;
    }
}

?>
