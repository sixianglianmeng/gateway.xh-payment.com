<?php

namespace app\lib\payment\channels\yb;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\common\models\model\Order;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;
use Yii;

/**
 * 易宝接口
 *
 * @package app\lib\payment\channels\yzb
 */
class YbBasePayment extends BasePayment
{
    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
    }

    /*
     * 解析异步通知请求，返回订单
     *
     * @return array self::RECHARGE_NOTIFY_RESULT
     */
    public function parseNotifyRequest(array $request){
        //check sign
        return $this->parseReturnRequest($request);
    }

    /*
     * 解析同步通知请求，返回订单
     * 返回订单对象表示请求验证成功且已经支付成功，可进行下一步业务
     * 返回int表示请求验证成功，订单未支付完成,int为订单在三方的状态
     * 其它表示错误
     *
     * @return array self::RECHARGE_NOTIFY_RESULT
     */
    public function parseReturnRequest(array $request){

        $data['p1_MerId']      = ControllerParameterValidator::getRequestParam($request, 'p1_MerId', null, Macro::CONST_PARAM_TYPE_STRING, 'p1_MerId错误！', [5]);
        $data['r0_Cmd']     = ControllerParameterValidator::getRequestParam($request, 'r0_Cmd', null, Macro::CONST_PARAM_TYPE_STRING, 'r0_Cmd错误！', [1]);
        $data['r1_Code']     = ControllerParameterValidator::getRequestParam($request, 'r1_Code', null, Macro::CONST_PARAM_TYPE_STRING, 'r1_Code错误！', [1]);
        $data['r2_TrxId']     = ControllerParameterValidator::getRequestParam($request, 'r2_TrxId', null, Macro::CONST_PARAM_TYPE_STRING, 'r2_TrxId错误！', [5]);
        $data['r3_Amt']  = ControllerParameterValidator::getRequestParam($request, 'r3_Amt', null, Macro::CONST_PARAM_TYPE_DECIMAL, 'r3_Amt错误！');
        $data['r4_Cur']   = ControllerParameterValidator::getRequestParam($request, 'r4_Cur', null, Macro::CONST_PARAM_TYPE_STRING, 'r4_Cur错误！', [2]);
        $data['r5_Pid']   = ControllerParameterValidator::getRequestParam($request, 'r5_Pid', '', Macro::CONST_PARAM_TYPE_STRING, 'r5_Pid错误！');
        $data['r6_Order'] = ControllerParameterValidator::getRequestParam($request, 'r6_Order', '', Macro::CONST_PARAM_TYPE_ORDER_NO, 'r6_Order错误！', [3]);
        $data['r7_Uid']       = ControllerParameterValidator::getRequestParam($request, 'r7_Uid', '', Macro::CONST_PARAM_TYPE_STRING, 'r7_Uid错误！');
        $data['r8_MP']       = ControllerParameterValidator::getRequestParam($request, 'r8_MP', '', Macro::CONST_PARAM_TYPE_STRING, 'r8_MP错误！');
        $data['r9_BType']       = ControllerParameterValidator::getRequestParam($request, 'r9_BType', '', Macro::CONST_PARAM_TYPE_STRING, 'r9_BType错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'hmac', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);
        $signHmacSafe                = ControllerParameterValidator::getRequestParam($request, 'hmac_safe', null, Macro::CONST_PARAM_TYPE_STRING, 'hmac_safe错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['r6_Order']);
        $this->setPaymentConfig($order->channelAccount);
        $this->setOrder($order);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY,
            'merchant_id'=>$order->merchant_id,
            'merchant_name'=>$order->merchant_account,
            'channel_account_id'=>$order->channelAccount->id,
            'channel_name'=>$order->channelAccount->channel_name,
        ];

        $localSign = self::hmacMd5(implode($data),trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(
            !empty($request['r1_Code']) && $request['r1_Code'] == '1'
            && !empty($request['r0_Cmd']) && $request['r0_Cmd'] == 'Buy'
            && $data['r3_Amt']>0
        ) {
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['r3_Amt'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['channel_order_no'] = $data['r2_TrxId'];
            $ret['data']['trade_status'] = Order::STATUS_PAID;
        }

        return $ret;
    }

    /*
     * 网银支付
     * 对应文档的银行网关快捷PC支付（浏览器请求）
     *
     * return array ['url'=>'get跳转链接','formHtml'=>'自动提交的form表单HTML']
     */
    public function webBank()
    {

        $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = ['p0_Cmd' => 'Buy',
                   'p1_MerId' => $this->order['channel_merchant_id'],
                   'p2_Order' => $this->order['order_no'],
                   'p3_Amt' => bcadd(0, $this->order['amount'], 2),
                   'p4_Cur' => 'CNY', 'p5_Pid' => '', 'p6_Pcat' => '', 'p7_Pdesc' => '',
                   'p8_Url' => str_replace('https','http',Yii::$app->request->hostInfo)."/gateway/v1/web/yb/return",
                   'p9_SAF' => '0',
                   'pb_ServerNotifyUrl' => str_replace('https','http',Yii::$app->request->hostInfo)."/gateway/v1/web/yb/notify",
                   'pa_MP' => '',
//                   'pd_FrpId' => $bankCode,
                   'pm_Period' => '7','pn_Unit' => 'day', 'pr_NeedResponse' => '1', 'pt_UserName' => '', 'pt_PostalCode' => '', 'pt_Address' => '',
                   'pt_TeleNo' => '','pt_Mobile' => '', 'pt_Email' => '', 'pt_LeaveMessage' => ''
        ];
        $params['hmac'] = self::hmacMd5(implode($params),trim($this->paymentConfig['key']));
        $params['hmac_safe'] = self::getHamcSafe($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/app-merchant-proxy/node';

        $formTxt = self::buildForm($params,$requestUrl);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$this->order->merchant_id,
            'merchant_name'=>$this->order->merchant_account,
            'channel_account_id'=>$this->order->channelAccount->id,
            'channel_name'=>$this->order->channelAccount->channel_name,
        ];
        LogicApiRequestLog::outLog($requestUrl, 'POST', '', 200,0, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['formHtml'] = $formTxt;

        return $ret;
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'payKey'=>$this->paymentConfig['payKey'],
            'merchantNo'=>$this->order['channel_merchant_id'],
            'orderNo'=>$this->order['order_no'],
            'serviceType'=>'Q001',
        ];
        $params = ['p0_Cmd' => 'QueryOrdDetail',
                   'p1_MerId' => $this->order['channel_merchant_id'],
                   'p2_Order' => $this->order['order_no'],
                   'pv_Ver' => '3.0',
                   'p3_ServiceType' => '2',
        ];

        $params['hmac'] = self::hmacMd5(implode($params),trim($this->paymentConfig['key']));
        $params['hmac_safe'] = self::getHamcSafe($params,trim($this->paymentConfig['key']));

        $requestUrl = 'https://cha.yeepay.com/app-merchant-proxy/command';
        $resTxt = self::post($requestUrl, $params);
        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                !empty($res['r1_Code']) && $res['r1_Code'] == '1'
                && !empty($res['r0_Cmd']) && $res['r0_Cmd'] == 'QueryOrdDetail'
            ) {
                if($res['rb_PayStatus'] == 'SUCCESS' && $res['r3_Amt']>0){
                    $ret['data']['trade_status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['r3_Amt'];
                    $ret['data']['trade_status'] = Order::STATUS_PAID;
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??'订单查询失败:'.$resTxt;
            }
        }

        return  $ret;
    }


    /**
     * 生成通知响应内容
     *
     * @param boolean $isSuccess
     * @return string
     */
    public static function createdResponse($isSuccess)
    {
        $str = 'FAIL';
        if($isSuccess){
            $str = 'SUCCESS';
        }
        return $str;
    }

    /**
     * 获取支付下一跳的地址/post表单
     *
     * @param string $url
     * @param $postParams
     * return array ['url'=>'','params'=>[]]
     */
    public function getNextPaymentForm($url, $postParams=null)
    {
        if($postParams){
            $htmlTxt = self::post($url,$postParams);
        }
        $htmlTxt = self::httpGet($url);

        $crawler = new Crawler($htmlTxt);
        $jumpUrl = '';
        foreach ($crawler->filter('form') as $n){
            $jumpUrl = $n->getAttribute('action');
        }
        $jumpParams = [];
        foreach ($crawler->filter('form > input') as $input) {
            $field = $input->getAttribute('name');
            if(!$field) continue;
            $jumpParams[$field] = $input->getAttribute('value');

        }

        return ['url'=>$jumpUrl,'params'=>$jumpParams];
    }

    /**
     * 余额查询,此通道没有余额查询接口.但是需要做伪方法,防止批量实时查询失败.
     *
     * return  array BasePayment::BALANCE_QUERY_RESULT
     */
    public function balance()
    {
    }

    #响应参数转换成数组
    function getResp($respdata)
    {
        $result = explode("\n",$respdata);
        $output = array();

        foreach ($result as $data)
        {
            $arr = explode('=',$data);
            $output[$arr[0]] =  urldecode($arr[1]);
        }

        return $output;
    }

    #生成本地签名hmac(不适用于回调通知)
    public static function hmacLocal($data,$merchantKey)
    {
        $text="";
            foreach ($data as $key=>$value)
        {
            if(isset($key) && $key!="hmac" && $key!="hmac_safe")
            {

                $text .=    $value;
            }

        }
        return self::hmacMd5($text,$merchantKey);

    }


    //生成本地的安全签名数据
    public static function getHamcSafe(array $data,$secretKey)
    {
        $text="";
        foreach ($data as $key=>$value)
        {
            if( $key!="hmac" && $key!="hmac_safe" && $value !=null)
            {

                $text .=  $value."#" ;
            }

        }
        $text1= rtrim( trim($text), '#' ); ;
        return self::hmacMd5($text1,$secretKey);

    }


    //生成hmac
    public static function hmacMd5(string $data,$key)
    {
        // RFC 2104 HMAC implementation for php.
        // Creates an md5 HMAC.
        // Eliminates the need to install mhash to compute a HMAC
        // Hacked by Lance Rushing(NOTE: Hacked means written)

        //需要配置环境支持iconv，否则中文参数不能正常处理
        $key = iconv("GBK","UTF-8",$key);
        $data = iconv("GBK","UTF-8",$data);
        $b = 64; // byte length for md5
        if (strlen($key) > $b) {
            $key = pack("H*",md5($key));
        }
        $key = str_pad($key, $b, chr(0x00));
        $ipad = str_pad('', $b, chr(0x36));
        $opad = str_pad('', $b, chr(0x5c));
        $k_ipad = $key ^ $ipad ;
        $k_opad = $key ^ $opad;


        return md5($k_opad . pack("H*",md5($k_ipad . $data)));
    }

}