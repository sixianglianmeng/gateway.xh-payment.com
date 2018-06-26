<?php

namespace app\lib\payment\channels\yzb;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\Channel;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Remit;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Symfony\Component\DomCrawler\Crawler;
use Yii;

/**
 * 易支宝接口
 *
 * @package app\lib\payment\channels\yzb
 */
class YzbBasePayment extends BasePayment
{
    const  TRADE_STATUS_SUCCESS = 'success';
    const  TRADE_STATUS_PROCESSING = 'paying';
    const  TRADE_STATUS_FAIL = 'failed';

    const  PAY_TYPE_MAPS = [
        Channel::METHOD_WEBBANK => 'S005A',
        Channel::METHOD_BANK_QUICK => 'S001A',
        Channel::METHOD_UNIONPAY_QR     => 'S010',
        Channel::METHOD_QQ_QR     => 'S004',
        Channel::METHOD_WECHAT_QR       => 'S002',
        Channel::METHOD_ALIPAY_QR       => 'S003',
        Channel::METHOD_BANK_H5     => 'S006',
        Channel::METHOD_QQ_H5           => 'S013',
        Channel::METHOD_WECHAT_H5       => 'S014',
        Channel::METHOD_ALIPAY_H5       => 'S015',
        Channel::METHOD_JD_QR           => 'S011',
    ];

    const MSG_LIST = [
        '101'      => '商户不存在',
        '102'      => '商户配置有误',
        '103'      => '商户支付参数配置有误',
        '1003'     => '微信异常',
        '1004'     => '订单异常',
        '1005'     => '交易记录状态不成功',
        '1006'     => '支付宝异常',
        '1007'     => '参数异常',
        '1008'     => '通道异常',
        '1010'     => '订单不存在',
        '1201'     => '可代付资金不足',
        '10010001' => '账户不存在',
        '10010002' => '余额不足，减款超限',
        '10010003' => '解冻金额超限',
        '10010004' => '冻结金额超限',
        '10010005' => '账户类型不能为空',
    ];

    const BANKS = [
        "01000000"=>"邮政储蓄银行",
        "03050000"=>"中国民生银行",
        "03060000"=>"广发银行",
        "03070000"=>"平安银行(深圳发展)",
        "03080000"=>"招商银行",
        "03090000"=>"兴业银行",
        "03100000"=>"浦发银行",
        "03200000"=>"东亚银行",
        "04031000"=>"北京银行",
        "65012900"=>"上海农村商业银行",
        "UNIONPAY"=>"银联支付",
        "01020000"=>"工商银行",
        "SHB"=>"上海银行",
        "04083320"=>"宁波银行",
        "03290000"=>"天津银行",
        "04243010"=>"南京银行",
        "CBHB"=>"渤海银行",
        "01030000"=>"农业银行",
        "01040000"=>"中国银行",
        "01050000"=>"建设银行",
        "03010000"=>"交通银行",
        "03020000"=>"中信银行",
        "03030000"=>"光大银行",
        "03040000"=>"华夏银行",
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

        $data['payKey']      = ControllerParameterValidator::getRequestParam($request, 'payKey', null, Macro::CONST_PARAM_TYPE_STRING, 'payKey错误！', [5]);
        $data['orderNo']     = ControllerParameterValidator::getRequestParam($request, 'orderNo', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['orderPrice']  = ControllerParameterValidator::getRequestParam($request, 'orderPrice', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['orderDate']   = ControllerParameterValidator::getRequestParam($request, 'orderDate', null, Macro::CONST_PARAM_TYPE_STRING, '订单时间错误！', [4]);
        $data['orderTime']   = ControllerParameterValidator::getRequestParam($request, 'orderTime', null, Macro::CONST_PARAM_TYPE_STRING, 'orderTime错误！', [10]);
        $data['serviceType'] = ControllerParameterValidator::getRequestParam($request, 'serviceType', '', Macro::CONST_PARAM_TYPE_STRING, 'serviceType错误！', [3]);
        $data['trxNo']       = ControllerParameterValidator::getRequestParam($request, 'trxNo', null, Macro::CONST_PARAM_TYPE_STRING, '平台订单号错误！', [3]);
        $data['tradeStatus'] = ControllerParameterValidator::getRequestParam($request, 'tradeStatus', null, Macro::CONST_PARAM_TYPE_STRING, 'tradeStatus错误！', [3]);
        $data['productName'] = ControllerParameterValidator::getRequestParam($request, 'productName', '', Macro::CONST_PARAM_TYPE_STRING, 'productName错误！');
        $sign                = ControllerParameterValidator::getRequestParam($request, 'sign', null, Macro::CONST_PARAM_TYPE_STRING, 'sign错误！', [3]);

        $order = LogicOrder::getOrderByOrderNo($data['orderNo']);
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

        $localSign = self::md5Sign($request,trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_NOTIFY_RESULT;
        if(!empty($request['tradeStatus']) && $request['tradeStatus'] == 'SUCCESS' && $data['orderPrice']>0) {
            $ret['data']['order'] = $order;
            $ret['data']['order_no'] = $order->order_no;
            $ret['data']['amount'] = $data['orderPrice'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['channel_order_no'] = $data['trxNo'];
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
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'serviceType'=>self::PAY_TYPE_MAPS[$this->order['pay_method_code']],
            'version'=>'v100',
            'bankCardType'=>'C01',
            'bankCode'=>$bankCode,
            'orderPrice'=>bcadd(0, $this->order['amount'], 2),
            'orderTime'=>date('YmdHis'),
            'notifyUrl'=>str_replace('https','http',str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri']))."/gateway/v1/web/yzb/notify",
            'returnUrl'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/yzb/return",
            'productName'=>'账户充值',

            'merchantNo'=>$this->order['channel_merchant_id'],
            'payKey'=>$this->paymentConfig['payKey'],

            'orderNo'=>$this->order['order_no'],
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl,$params);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$this->order->merchant_id,
            'merchant_name'=>$this->order->merchant_account,
            'channel_account_id'=>$this->order->channelAccount->id,
            'channel_name'=>$this->order->channelAccount->channel_name,
        ];
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code']) && $res['code'] == '00000'
                && !empty($res['payUrl'])
            ) {
                $ret['status'] = Macro::SUCCESS;

                $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;

                if(substr($res['payUrl'],0,4)=='http'){
                    $ret['data']['url'] = $res['payUrl'];
                }else{
                    $ret['data']['formHtml'] = $res['payUrl'];
                }
            } else {
                $ret['message'] = $res['message']??'付款提交失败';
            }
        }


        return $ret;
    }

    /**
     * 网银H5/WAP支付
     */
    public function bankH5()
    {
        //{"code":"00000","orderNo":"11518060700540636387","payUrl":"<form id=\"paysubmit\" name=\"paysubmit\" action=\"http://gateway.szmfda.cn/transation/gateway/03080000_C01/11518060700540636387/cce071ca9cec469b9588d8c5603e6b41\" method=\"POST\"><input type=\"submit\" value=\"确认\" style=\"display:none;\"></form><script>document.forms['paysubmit'].submit();</script>","orderPrice":"29.62","message":"操作成功","productName":"账户充值","status":"SUCCESS"}
        $ret = $this->webBank();

        return $ret;
    }

    /*
     * 微信扫码支付
     */
    public function wechatQr()
    {
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'serviceType'=>self::PAY_TYPE_MAPS[$this->order['pay_method_code']],
            'version'=>'v100',
            'orderPrice'=>bcadd(0, $this->order['amount'], 2),
            'orderTime'=>date('YmdHis'),
            'notifyUrl'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/yzb/notify",
            'returnUrl'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/yzb/return",
            'productName'=>'账户充值',
            'merchantNo'=>$this->order['channel_merchant_id'],
            'payKey'=>$this->paymentConfig['payKey'],
            'orderNo'=>$this->order['order_no'],
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl,$params);
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$this->order->merchant_id,
            'merchant_name'=>$this->order->merchant_account,
            'channel_account_id'=>$this->order->channelAccount->id,
            'channel_name'=>$this->order->channelAccount->channel_name,
        ];
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        //{"pl_orderNo":"01d8b14347ae42ad9035ad30da63dc76","codeUrl":"https://qr.95516.com/00010000/62293851777707666543258731823977","code":"00000","orderNo":"10718060713541038112","orderPrice":"70.15","message":"操作成功","productName":"账户充值","status":"SUCCESS"}
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code']) && $res['code'] == '00000'
                && !empty($res['codeUrl'])
            ) {
                $ret['status'] = Macro::SUCCESS;

                $ret['data']['type'] = self::RENDER_TYPE_QR;

                $ret['data']['qr'] = $res['codeUrl'];
                $ret['data']['qr'] = $res['pl_orderNo'];
            } else {
                $ret['message'] = $res['message']??'付款提交失败';
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
     * 京东扫码支付
     */
    public function jdQr()
    {
        return $this->wechatQr();
    }


    /**
     * 银联扫码支付
     */
    public function unoinPayQr()
    {
        return $this->wechatQr();
    }


    /**
     * 微信H5支付
     */
    public function wechatH5()
    {
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }

        $params = [
            'serviceType'=>self::PAY_TYPE_MAPS[$this->order['pay_method_code']],
            'version'=>'v100',
            'orderPrice'=>bcadd(0, $this->order['amount'], 2),
            'orderTime'=>date('YmdHis'),
            'notifyUrl'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/yzb/notify",
            'returnUrl'=>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/yzb/return",
            'productName'=>'账户充值',
            'merchantNo'=>$this->order['channel_merchant_id'],
            'payKey'=>$this->paymentConfig['payKey'],
            'orderNo'=>$this->order['order_no'],
            'termIp'=>Util::getClientIp(),
        ];

        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl,$params);
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$this->order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_RECHARGE_ADD,
            'merchant_id'=>$this->order->merchant_id,
            'merchant_name'=>$this->order->merchant_account,
            'channel_account_id'=>$this->order->channelAccount->id,
            'channel_name'=>$this->order->channelAccount->channel_name,
        ];
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        //{"pl_orderNo":"01d8b14347ae42ad9035ad30da63dc76","codeUrl":"https://qr.95516.com/00010000/62293851777707666543258731823977","code":"00000","orderNo":"10718060713541038112","orderPrice":"70.15","message":"操作成功","productName":"账户充值","status":"SUCCESS"}
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code']) && $res['code'] == '00000'
                && !empty($res['codeUrl'])
            ) {
                $ret['status'] = Macro::SUCCESS;

                $ret['data']['type'] = self::RENDER_TYPE_QR;

                $ret['data']['qr'] = $res['codeUrl'];
                $ret['data']['qr'] = $res['pl_orderNo'];
            } else {
                $ret['message'] = $res['message']??'付款提交失败';
            }
        }

        return $ret;

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
        if(empty(self::BANKS[$bankCode])){
            throw new OperationFailureException('出款程序银行代码未配置:BANKS',Macro::ERR_UNKNOWN);
        }
        $params = [
            'serviceType'=>'DF003',
            'version'=>'v100',
            'orderPrice'=>bcadd(0, $this->remit['amount'], 2),
            'orderTime'=>date('YmdHis'),
            'merchantNo'=>$this->remit['channel_merchant_id'],
            'payKey'=>$this->paymentConfig['payKey'],
            'orderNo'=>$this->remit['order_no'],
            'accountName'=>$this->remit['bank_account'],
            'bankCard'=>$this->remit['bank_no'],
            'bankName'=>self::BANKS[$bankCode],
            'notifyUrl'=>"http://".Yii::$app->params['domain.gateway']."/gateway/v1/web/yzb/remit-notify",
        ];
        $params['sign'] = self::md5Sign($params, trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit to bank raw result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code'])
            ) {
                //打款成功是00000（大部分情况是能够直接打款成功），
                //88888还在打款中，需要等待异步回调或请求查询结果
                //其他都是失败
                if($res['code'] == '00000'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                }elseif($res['code'] == '88888'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }else{
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = $res['message']??"出款提交失败({$resTxt})";
                }
                if($res['pl_orderNo']){
                    $ret['data']['channel_order_no'] = $res['pl_orderNo'];
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??"出款提交失败({$resTxt})";
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
            'payKey'=>$this->paymentConfig['payKey'],
            'merchantNo'=>$this->remit['channel_merchant_id'],
            'orderNo'=>$this->remit['order_no'],
            'serviceType'=>'DF_QUERY_001',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);

        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;

        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code'])
                && isset($res['tradeStatus'])
            ) {
                //交易状态  SUCCESS 打款成功 UNKNOW  未知的结果， 请继续轮询 FAILED 打款失败
                //注意：任何未明确返回tradeStatus状态为FAILED都不能认为失败！！！
                if($res['code'] == '00000' && $res['tradeStatus']=='SUCCESS'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                }elseif($res['code'] == '00000' && $res['tradeStatus']=='FAILED'){
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                    $ret['message'] = '三方返回银行出款失败';
                }else{
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                }
                if($res['pl_orderNo']){
                    $ret['data']['channel_order_no'] = $res['pl_orderNo'];
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??"出款查询失败({$resTxt})";;
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
            'payKey'=>$this->paymentConfig['payKey'],
            'merchantNo'=>$this->order['channel_merchant_id'],
            'orderNo'=>$this->order['order_no'],
            'serviceType'=>'Q001',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'];
        $resTxt = self::post($requestUrl, $params);
        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['status']) && $res['status'] == 'SUCCESS'
                && isset($res['code'])
                && isset($res['isPaid'])
            ) {
                //交易状态  SUCCESS 打款成功 UNKNOW  未知的结果， 请继续轮询 FAILED 打款失败
                //注意：任何未明确返回tradeStatus状态为FAILED都不能认为失败！！！
                if($res['code'] == '00000' && $res['isPaid']=='YES' && $res['orderPrice']>0){
                    $ret['data']['trade_status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['orderPrice'];
                }

                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['message'] = $res['message']??'订单查询失败';
            }
        }

        return  $ret;
    }

    /*
     * 解析出款异步通知请求，返回订单
     *
     * @return array self::RECHARGE_NOTIFY_RESULT
     */
    public function parseRemitNotifyRequest(array $request){

        $data['payKey'] = ControllerParameterValidator::getRequestParam($request, 'payKey',null,Macro::CONST_PARAM_TYPE_STRING, 'payKey错误！',[5]);
        $data['orderNo'] = ControllerParameterValidator::getRequestParam($request, 'orderNo',null,Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['payAmount'] = ControllerParameterValidator::getRequestParam($request, 'payAmount',null,Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['serviceType'] = ControllerParameterValidator::getRequestParam($request, 'serviceType','',Macro::CONST_PARAM_TYPE_STRING, 'serviceType错误！',[3]);
        $data['pl_orderNo'] = ControllerParameterValidator::getRequestParam($request, 'pl_orderNo',null,Macro::CONST_PARAM_TYPE_STRING, '平台订单号错误！',[3]);
        $data['tradeStatus'] = ControllerParameterValidator::getRequestParam($request, 'tradeStatus',null,Macro::CONST_PARAM_TYPE_STRING, 'tradeStatus错误！',[3]);
        $data['merchantNo'] = ControllerParameterValidator::getRequestParam($request, 'merchantNo',null,Macro::CONST_PARAM_TYPE_STRING, 'merchantNo错误！',[1]);
        $sign = ControllerParameterValidator::getRequestParam($request, 'sign',null,Macro::CONST_PARAM_TYPE_STRING, 'sign错误！',[3]);

        $remit = LogicRemit::getOrderByOrderNo($data['orderNo']);
        $this->setPaymentConfig($remit->channelAccount);
        $this->setRemit($remit);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY,
            'merchant_id'=>$remit->merchant_id,
            'merchant_name'=>$remit->merchant_account,
            'channel_account_id'=>$remit->channelAccount->id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];

        $localSign = self::md5Sign($request,trim($this->paymentConfig['key']));
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret = self::RECHARGE_QUERY_RESULT;
        $ret['data']['remit'] = $remit;
        $ret['data']['order_no'] = $remit->order_no;

        if(!empty($request['tradeStatus']) && $request['tradeStatus'] =='SUCCESS' && $data['payAmount']>0) {
            $ret['data']['amount'] = $data['payAmount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
            $ret['data']['channel_order_no'] = $data['pl_orderNo'];
        }elseif(!empty($request['tradeStatus']) && $request['tradeStatus'] =='FAILED ') {
            $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
        }

        return $ret;
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
     * 根据平台支付类型获取恒星对应的支付类型字符串
     *
     * @param string $type 平台支付类型
     *
     * @return mixed|string
     */
    public static function getPayType($type)
    {
        if(empty(self::PAY_TYPE_MAPS[$type])){
            Util::throwException(Macro::ERR_PAYMENT_CHANNEL_TYPE_NOT_ALLOWED);
        }

        return self::PAY_TYPE_MAPS[$type] ?? '';
    }

    /**
     *
     * 获取参数排序md5签名
     *
     * @param array $params 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */
    public static function md5Sign(array $params, string $signKey){
        if (is_array($params)) {
            unset($params['sign']);

            $a      = $params;
            $params = array();
            foreach ($a as $key => $value) {
                if($value=='') continue;
                $params[] = "$key=$value";
            }
            sort($params,SORT_STRING);
            $params = implode('&', $params);
        } else {
            return '';
        }
        $signStr = strtoupper(md5($params.'&paySecret='.$signKey));
        //        Yii::info(['md5Sign string: ',$signStr,$params]);
        return $signStr;
    }

    /**
     * 余额查询,此通道没有余额查询接口.但是需要做伪方法,防止批量实时查询失败.
     *
     * return  array BasePayment::BALANCE_QUERY_RESULT
     */
    public function balance()
    {
    }

}