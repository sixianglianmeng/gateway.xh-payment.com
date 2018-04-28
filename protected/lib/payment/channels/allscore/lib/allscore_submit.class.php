<?php
/* *
 * 类名：AllscoreSubmit
 * 功能：商银信接口请求提交类
 * 详细：构造商银信接口表单HTML文本，获取远程HTTP数据
 * 版本：1.0
 * 日期：2011-11-03
 * 说明：
 * 以下代码只是为了方便商户测试而提供的样例代码，商户可以根据自己网站的需要，按照技术文档编写,并非一定要使用该代码。
 * 该代码仅供学习和研究商银信接口使用，只是提供一个参考。
 */
require_once(realpath(__DIR__ . '/') . "/allscore_core.function.php");

class AllscoreSubmit
{
    /**
     * 生成要请求给商银信的参数数组
     * @param $para_temp 请求前的参数数组
     * @param $allscore_config 基本配置信息数组
     * @return 要请求的参数数组
     */
    function buildRequestPara($para_temp, $allscore_config)
    {
        //除去待签名参数数组中的空值和签名参数
        $para_filter = paraFilter($para_temp);

        //对待签名参数数组排序
        $para_sort = argSort($para_filter);
        //logResult(print_r($para_sort,1));
        if ($para_temp['signType'] == 'MD5') {

            //生成签名结果
            $mysign = buildMysign($para_sort, trim($allscore_config['key']));
        } else {
            //生成签名结果
            $mysign = buildMysignRSA($para_sort, trim($allscore_config['MerchantPrivateKey']));
        }

        //签名结果与签名方式加入请求提交参数组中
        $para_sort['sign']     = $mysign;
        $para_sort['signType'] = $para_temp['signType'];
        //logResult("sign".$mysign);

        return $para_sort;
    }


    /**
     * 生成要请求给商银信的参数数组
     * @param $para_temp 请求前的参数数组
     * @param $allscore_config 基本配置信息数组
     * @return 要请求的参数数组字符串
     */
    function buildRequestParaToString($para_temp, $allscore_config)
    {
        //待请求参数数组
        $para = $this->buildRequestPara($para_temp, $allscore_config);

        //把参数组中所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
        $request_data = createLinkstring($para);

        return $request_data;
    }

    /**
     * 生成要请求给商银信的URL地址
     * @param $para_temp 请求前的参数数组
     * @param $allscore_config 基本配置信息数组
     * @return 要请求的URL地址
     */
    function buildRequestUrl($gateway, $para_temp, $allscore_config)
    {

        //待请求参数数组
        $para = $this->buildRequestPara($para_temp, $allscore_config);
        //把参数组中所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
        $request_data = createLinkEncode($para);
        logResult("request_data=" . print_r($request_data, 1));
        return $gateway . $request_data;
    }


    /**
     * 构造提交表单HTML数据
     * @param $para_temp 请求参数数组
     * @param $gateway 网关地址
     * @param $method 提交方式。两个值可选：post、get
     * @param $button_name 确认按钮显示文字
     * @return 提交表单HTML文本
     */
    function buildForm($para_temp, $gateway, $method, $button_name, $allscore_config)
    {

        //待请求参数数组
        $para = $this->buildRequestPara($para_temp, $allscore_config);

        $sHtml = "<form id='allscoresubmit' name='allscoresubmit' action='" . $gateway . "' method='" . $method . "'>";

        foreach ($para as $key => $value) {
            logResult($key . "==" . $value);
            $sHtml .= "<input type='hidden' name='" . $key . "' value='" . $value . "'/>";
        }
        //submit按钮控件请不要含有name属性
        $sHtml = $sHtml . "<input type='submit' value='" . $button_name . "' style='display:none;'></form>";

        $sHtml = $sHtml . "<script>document.forms['allscoresubmit'].submit();</script>";
        logResult("提交的表单数据:" . $sHtml);
        return $sHtml;
    }


    /**
     * 构造自动提交表单HTML数据
     * @param $para_temp 请求参数数组
     * @param $gateway 网关地址
     * @param $method 提交方式。两个值可选：post、get
     * @param $button_name 确认按钮显示文字
     * @return 提交表单HTML文本
     */
    function buildAutoForm($para_temp, $gateway, $method, $button_name, $allscore_config)
    {
        //待请求参数数组
        $para = $this->buildRequestPara($para_temp, $allscore_config);

        $sHtml = "<form id='allscoresubmit' name='allscoresubmit' action='" . $gateway . "' method='" . $method . "'>";

        foreach ($para as $key => $value) {
            $sHtml .= "<input type='hidden' name='" . $key . "' value='" . $value . "'/>";
        }
        //submit按钮控件请不要含有name属性
        $sHtml = $sHtml . "<input type='submit' value='" . $button_name . "'></form>";

        $sHtml = $sHtml . "<script>document.forms['allscoresubmit'].submit();</script>";

        return $sHtml;
    }

    function encryptForPuKey($data, $pubKey)
    {
        return encryptForPuKey($data, $pubKey);
    }


    function xml_to_array($xml)
    {
        $reg = "/<(\\w+)[^>]*?>([\\x00-\\xFF]*?)<\\/\\1>/";
        if (preg_match_all($reg, $xml, $matches)) {
            $count = count($matches[0]);
            $arr   = array();
            for ($i = 0; $i < $count; $i++) {
                $key = $matches[1][$i];
                $val = xml_to_array($matches[2][$i]);  // 递归
                if (array_key_exists($key, $arr)) {
                    if (is_array($arr[$key])) {
                        if (!array_key_exists(0, $arr[$key])) {
                            $arr[$key] = array($arr[$key]);
                        }
                    } else {
                        $arr[$key] = array($arr[$key]);
                    }
                    $arr[$key][] = $val;
                } else {
                    $arr[$key] = $val;
                }
            }
            return $arr;
        } else {
            return $xml;
        }
    }

}

?>