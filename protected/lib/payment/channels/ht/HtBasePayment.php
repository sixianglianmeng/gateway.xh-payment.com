<?php

namespace app\lib\payment\channels\ht;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\LogApiRequest;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;
use Yii;

class HtBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 'success';
    const  TRADE_STATUS_PROCESSING = 'paying';
    const  TRADE_STATUS_FAIL = 'failed';

    const PAY_TYPE_MAP = [
        "WY"=>1,
        "WXQR"=>2,
        "ALIQR"=>3,
        "QQQR"=>5,
        "UNQR"=>7,
        "WXH5"=>10,
        "ALIH5"=>11,
        "QQH5"=>12,
        "WYKJ"=>13,
        "JDH5"=>14,
        "JDQR"=>17,
        "UNH5"=>18,
    ];

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

        $data['order_no'] = ControllerParameterValidator::getRequestParam($request, 'order_no',null,Macro::CONST_PARAM_TYPE_STRING, '订单号错误！');
        $data['order_amount'] = ControllerParameterValidator::getRequestParam($request, 'order_amount',null,Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['order_time'] = ControllerParameterValidator::getRequestParam($request, 'order_time',null,Macro::CONST_PARAM_TYPE_STRING, '订单时间错误！',[3]);
        $data['return_params'] = ControllerParameterValidator::getRequestParam($request, 'notifyTime','',Macro::CONST_PARAM_TYPE_STRING, '订单返回参数错误！',[3]);
        $data['merchant_code'] = ControllerParameterValidator::getRequestParam($request, 'merchant_code',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);
        $data['trade_no'] = ControllerParameterValidator::getRequestParam($request, 'trade_no',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单号错误！',[3]);
        $data['trade_time'] = ControllerParameterValidator::getRequestParam($request, 'trade_time',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单时间错误！',[3]);
        $data['trade_status'] = ControllerParameterValidator::getRequestParam($request, 'trade_status',null,Macro::CONST_PARAM_TYPE_STRING, '状态错误！',[3]);
        $data['notify_type'] = ControllerParameterValidator::getRequestParam($request, 'notify_type',null,Macro::CONST_PARAM_TYPE_STRING, '通知类型错误！',[3]);
        $data['merchant_code'] = ControllerParameterValidator::getRequestParam($request, 'merchant_code',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantId错误！',[3]);
        $data['return_params'] = ControllerParameterValidator::getRequestParam($request, 'return_params','',Macro::CONST_PARAM_TYPE_STRING, 'return_params错误！');

        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);
        //修复某段时间订单号携带_的bug
        $orderNo = $data['order_no'];
        if(strpos($data['order_no'],'_')!==false){
            $orderNoArr = explode('_',$data['order_no']);
            $orderNo = $orderNoArr[0];
        }

        $order = LogicOrder::getOrderByOrderNo($orderNo);
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

        $localSign = self::md5Sign($data,trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(!empty($request['trade_status']) && $request['trade_status'] == self::TRADE_STATUS_SUCCESS) {
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['order_amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['channel_order_no'] = $data['trade_no'];
        }
        elseif(!empty($request['trade_status']) && $request['trade_status'] == self::TRADE_STATUS_FAIL) {
            $ret['status'] =  Macro::FAIL;
        }
        else{
            $ret['status'] =  Macro::ERR_PAYMENT_PROCESSING;
        }

        //设置了请求日志，写入日志表
        LogicApiRequestLog::inLog($ret);

        return $ret;
    }

    /*
     * 生成网银支付跳转参数连接
     *
     * return array ['url'=>'get跳转链接','formHtml'=>'自动提交的form表单HTML']
     */
    public function webBank()
    {

        $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);
        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        if(empty(self::PAY_TYPE_MAP[$this->order['pay_method_code']])){
            throw new OperationFailureException("HT通道配置不支持此支付方式:".$this->order['pay_method_code'],Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        $params = [
            'notify_url'=>$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/notify",
            'return_url'=>$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/return",
            'bank_code'=>$bankCode,
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>self::PAY_TYPE_MAP[$this->order['pay_method_code']],
            'order_amount'=>$this->order['amount'],
            'req_referer'=>Yii::$app->request->referrer?Yii::$app->request->referrer:Yii::$app->request->getHostInfo().Yii::$app->request->url,
            'order_time'=>date("Y-m-d H:i:s"),
            'customer_ip'=>Yii::$app->request->remoteIP,
            'return_params'=>$this->order['order_no'],
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/pay.html';
        $getUrl = $requestUrl.'?'.http_build_query($params);

        //是否跳过汇通
        $skipHt = true;
        $form = '';
        if($skipHt){
            //跳过上游第一个地址,达到隐藏上游目的.
            //        $htmlTxt = file_get_contents($getUrl);
            $htmlTxt = self::httpGet($getUrl);
            //        $htmlTxt = '<form id="allscoresubmit" name="allscoresubmit" action="https://paymenta.allscore.com/olgateway/serviceDirect.htm" method="post"><input type="hidden"
            //name="subject" value="在线支付"/><input type="hidden" name="channel" value="B2C"/><input type="hidden" name="sign" value="TWZSeUttekFsYXZGRDJBYkNhVHZCVXg2ZFFTaklWYm9FKzFPSDBGY3JLZFk1SmVvL2cyNTlJMzg5ZDQzNGRqQ2h2MTdFUXdJcURPdWk3N2lDZVNVMmh5TmJBM1M0L3F0V1lreGIwL3hmTVN0ME5EZ1VRdzJrdFdwUnd1dE5pYy9XQThJZmtoYUxjYWdiSXUvRzNTMDkrWURjWnppUyt3ZkRrV1VnY2FZeFVjPQ=="/><input type="hidden" name="body" value="在线支付"/><input type="hidden" name="defaultBank" value="CMB"/><input type="hidden" name="merchantId" value="001018050404891"/><input type="hidden" name="service" value="directPay"/><input type="hidden" name="payMethod" value="bankPay"/><input type="hidden" name="outOrderId" value="1090520601796603"/><input type="hidden" name="transAmt" value="10.00"/><input type="hidden" name="cardAttr" value="01"/><input type="hidden" name="signType" value="RSA"/><input type="hidden" name="notifyUrl" value="https://sync.huitongvip.com/shangyinxin/notify_url.html"/><input type="hidden" name="inputCharset" value="UTF-8"/><input type="hidden" name="detailUrl" value=""/><input type="hidden" name="returnUrl" value="https://api.huitongvip.com/shangyinxin/notify_page.html"/><input type="submit" value="确认" style="display:none;"></form><script>document.forms[\'allscoresubmit\'].submit();</script>';

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
            Yii::info([$jumpUrl,$jumpParams]);
            if($jumpUrl && $jumpParams){
                //第二跳
//                $retTxt2 = self::post($jumpUrl,$jumpParams);
//                //        $retTxt2 = "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\"> <html xmlns=\"http://www.w3.org/1999/xhtml\"> <head> <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/> <title>网上支付系统</title> </head> <body> <body><form id = \"sform\" action=\"https://netpay.cmbchina.com/netpayment/BaseHttp.dll?PrePayC2?\" method=\"post\"><input type=\"hidden\" name=\"BranchID\" id=\"BranchID\" value=\"0010\"/><input type=\"hidden\" name=\"CoNo\" id=\"CoNo\" value=\"000254\"/><input type=\"hidden\" name=\"BillNo\" id=\"BillNo\" value=\"4204657401\"/><input type=\"hidden\" name=\"Amount\" id=\"Amount\" value=\"10.00\"/><input type=\"hidden\" name=\"Date\" id=\"Date\" value=\"20180601\"/><input type=\"hidden\" name=\"MerchantUrl\" id=\"MerchantUrl\" value=\"https://notice.allscore.com/ebank/cmb/pay/return\"/><input type=\"hidden\" name=\"MerchantPara\" id=\"MerchantPara\" value=\"\"/><input type=\"hidden\" name=\"MerchantCode\" id=\"MerchantCode\" value=\"|ApVquWqQM*mKe/BHWs7ZusA9jI/jw2CVpl8Bcv*weVMTPj9EfhM6bmPKcCaWKmdaMR3cqI8ZvamDl3g3GZjkG6Yysrt/lZQvVznw7zag9zN3hQa14p8Bnj*CBiFk7nkj8bge6FqWNz3H2tmgkZHbJUQxzz1wh6Yjq6rov6l825/h4uYAdA9Nf0SLT3Fj1fCMR0Bw*x8dYMlHQY8/Eebw9UDAEO373o*4fyM/7mdktAXwS8gMKLQB0toa4iSnbM6wNzbHhbptCjz1TxEz8CXcVr5OefvnTn1EN3bH6BdGahjdafLL18TM1KGgOc8cDimMXnbhsOI6neGaNK81eigzyjgI72XQvjduQAio/w==|5feddeb609c08f2ed7aa06ce00783a0d1d7e9f30\"/></form></body><script type=\"text/javascript\">document.getElementById(\"sform\").submit(); </script> </body> </html>";
//
//                $crawler = new Crawler($retTxt2);
//                $jumpUrl = '';
//                foreach ($crawler->filter('form') as $n){
//                    $jumpUrl = $n->getAttribute('action');
//                }
//                $jumpParams = [];
//                foreach ($crawler->filter('form > input') as $input) {
//                    $field = $input->getAttribute('name');
//                    if(!$field) continue;
//                    $jumpParams[$field] = $input->getAttribute('value');
//
//                }
//
//                Yii::info([$jumpUrl,$jumpParams]);

                $form = self::buildForm( $jumpParams, $jumpUrl);
            }
        }
        else{
            Yii::info("can not skip ht payment redirect");
            $form = self::buildForm($params, $requestUrl);
        }

        $ret = self::RECHARGE_WEBBANK_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['url'] = $getUrl;
        $ret['data']['formHtml'] = $form;

        return $ret;
    }

    /*
     * 微信扫码支付
     */
    public function wechatQr()
    {
        if(empty(self::PAY_TYPE_MAP[$this->order['pay_method_code']])){
            throw new OperationFailureException("HT通道配置不支持此支付方式:".$this->order['pay_method_code'],Macro::ERR_PAYMENT_TYPE_NOT_ALLOWED);
        }

        $params = [
            'notify_url'=>$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/notify",
            'return_url'=>$this->paymentConfig['paymentNotifyBaseUri']."/gateway/v1/web/ht/return",
            'bank_code'=>'',
            'merchant_code'=>$this->order['channel_merchant_id'],
            'order_no'=>$this->order['order_no'],
            'pay_type'=>self::PAY_TYPE_MAP[$this->order['pay_method_code']],
            'order_amount'=>$this->order['amount'],
            'req_referer'=>Yii::$app->request->referrer?Yii::$app->request->referrer:Yii::$app->request->getHostInfo().Yii::$app->request->url,
            'order_time'=>date("Y-m-d H:i:s"),
            'customer_ip'=>Yii::$app->request->remoteIP,
            'return_params'=>$this->order['order_no'],
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/order.html';
        $resTxt = self::post($requestUrl,$params);

        //"{"flag":"00","msg":"下单成功","orderId":"106030602907794","payType":"2","qrCodeUrl":"https://api.huitongvip.com/wf2/order.html?id=8a0c808663b537fd0163c03d4a8a2377","sign":"88d1b6ac0c708b386ab4a5af9f75574f","transId":"10218060219213554285555"}"
        $ret = self::RECHARGE_WEBBANK_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (isset($res['flag']) && $res['flag'] == '00') {
                $ret['status'] = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['transId'];

                if(!empty($res['qrCodeUrl'])){
                    if(Util::isMobileDevice() && substr($res['qrCodeUrl'],0,4)=='http'){
                        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                        $ret['data']['url'] = $res['qrCodeUrl'];
                    }else{
                        $ret['data']['type'] = self::RENDER_TYPE_QR;
                        $ret['data']['qr'] = $res['qrCodeUrl'];
                    }

                }
            } else {
                $ret['message'] = $res['msg']??'付款提交失败';
            }
        }

        return $ret;
    }


    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        return $this->wechatQr();
    }

    /**
     * QQ扫码支付
     */
    public function qqQr()
    {
        return $this->wechatQr();
    }


    /**
     * 网银快捷支付
     */
    public function bankQuickPay()
    {
        return $this->wechatQr();
    }

    /**
     * 微信快捷扫码支付
     */
    public function wechatQuickQr()
    {
        return $this->wechatQr();
    }

    /**
     * 微信H5支付
     */
    public function wechatH5()
    {
        return $this->wechatQr();

    }

    /**
     * 支付宝H5支付
     */
    public function alipayH5()
    {
        return $this->wechatQr();
    }


    /**
     * QQ H5支付
     */
    public function qqH5()
    {
        return $this->wechatQr();
    }

    /**
     * 京东钱包支付
     */
    public function jdWallet()
    {
        return $this->wechatQr();
    }

    /**
     * 银联微信扫码支付
     */
    public function unoinPayQr()
    {
        return $this->wechatQr();
    }


    /**
     * 京东H5支付
     */
    public function jdh5()
    {
        return $this->wechatQr();
    }

    /**
     * 提交出款请求
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remit(){
        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }

        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code']);
        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'trade_no'=>$this->remit['order_no'],
            'order_amount'=>$this->remit['amount'],
            'order_time'=>date("Y-m-d H:i:s"),
            'account_name'=>$this->remit['bank_account'],
            'account_number'=>$this->remit['bank_no'],
            'bank_code'=>$bankCode,//$this->remit['bank_code'],
        ];
        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'] . '/remit.html';
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit to bank result: '.$this->remit['order_no'].' '.$resTxt);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::REMIT_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success'])) $ret['status'] = Macro::SUCCESS;
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['data']['channel_order_no'] = $res['order_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = $res['bank_status'];
            } else {
                $ret['message'] = $res['errror_msg']??"出款提交失败({$resTxt})";
            }
        }

        return  $ret;
    }

    /**
     * 提交出款状态查询
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remitStatus(){
        if(empty($this->remit)){
            throw new \app\common\exceptions\OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $params = [
            'merchant_code'=>$this->remit['channel_merchant_id'],
            'trade_no'=>$this->remit['order_no'],
            'now_date'=>date("Y-m-d H:i:s"),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/remit_query.html';
        $resTxt = self::post($requestUrl, $params);
        //记录请求日志
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            //仅代表请求成功,不代表业务成功
            if (isset($res['is_success'])) $ret['status'] = Macro::SUCCESS;

            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['data']['channel_order_no'] = $res['order_id'];
                //0 未处理，1 银行处理中 2 已打款 3 失败
                $ret['data']['bank_status'] = $res['bank_status'];
                if($res['bank_status']==3){
                    $ret['message'] = $res['errror_msg']??"银行处理失败({$resTxt})";
                }
            } else {
                $ret['message'] = $res['errror_msg']??"出款查询失败({$resTxt})";;
            }
        }

        return  $ret;
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'merchant_code'=>$this->order['channel_merchant_id'],
            'trade_no'=>$this->order['order_no'],
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/query.html';
        $resTxt = self::post($requestUrl, $params);

        $ret = self::REMIT_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['is_success']) && strtoupper($res['is_success']) == 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['channel_order_no'] = $res['order_id'];
                $ret['data']['trade_status'] = $res['trade_status'];
            } else {
                $ret['message'] = $res['errror_msg']??'收款订单查询失败';
            }
        }

        return  $ret;
    }

    /*
     * 查询余额
     *
     * @return array
     */
    public function balance(){
        $params = [
            'merchant_code'=>$this->paymentConfig['merchantId'],
            'query_time'=>date("Y-m-d H:i:s"),
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/balance.html';
        $resTxt = self::post($requestUrl, $params);

        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['is_success']) && strtoupper($res['is_success'])== 'TRUE') {
                $ret['status']         = Macro::SUCCESS;
                $ret['data']['balance'] = $res['money'];
                $ret['data']['frozen_balance'] = $res['freeze_money'];
            } else {
                $ret['message'] = $res['errror_msg']??'出款查询失败';
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
        $str = 'fail';
        if($isSuccess){
            $str = 'success';
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
}