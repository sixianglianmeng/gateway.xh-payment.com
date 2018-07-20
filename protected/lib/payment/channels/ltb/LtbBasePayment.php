<?php

namespace app\lib\payment\channels\ltb;

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
use Yii;

/**
 * 荔滔博支付接口
 *
 * @package app\lib\payment\channels\ltb
 */
class LtbBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_QQ_QR       => 'QQ',//QQ扫码
        Channel::METHOD_JD_QR       => 'JD',//京东扫码
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

        //按照文档获取所有签名参数,某些渠道签名参数不固定,也可以直接获取所有request
        $callbackParamsName = [
            'version',
            'merNo',
            'orderNo',
            'amount',
            'tradeType',
            'platOrderNo',
            'settDate',
            'status',
            'respCode',
            'respDesc',
            'sign'
        ];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['orderNo']      = ControllerParameterValidator::getRequestParam($request, 'orderNo', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount']  = ControllerParameterValidator::getRequestParam($request, 'amount', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
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

        $localSign = self::md5Sign($data, $this->paymentConfig['key']);
        if($sign != $localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }


        $ret = self::RECHARGE_NOTIFY_RESULT;
        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;
        $ret['msg'] = $data['respDesc'];

        if (!empty($data['respCode']) && $data['respCode'] == '0000' && !empty($data['status']) && $data['status'] == '100' && $data['amount']>0) {
            $ret['data']['amount'] = $data['amount'];
            $ret['status'] = Macro::SUCCESS;
            $ret['data']['status'] = Macro::SUCCESS;
            $ret['data']['trade_status'] = Order::STATUS_NOTPAY;
        }

        return $ret;
    }
    /**
     *
    1005	QQ扫码
    1007	京东扫码
     */
    /**
     * QQ扫码支付
     */
    public function qqQr()
    {
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }
        $params = [
            'version'               =>'1.0',
            'merNo'                 =>$this->order['channel_merchant_id'],
            'transTime'             =>date('YmdHis'),
            'orderNo'               =>$this->order['order_no'],
            'amount'                =>$this->order['amount'],
            'memberIp'              =>Yii::$app->request->remoteIP,
            'authCode'              =>'',
            'tradeType'             =>'1005',
            //页面通知地址。扫码选填，WAP必填
            'returnUrl'             =>'',
            'notifyUrl'             =>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/ltb/notify",
            'goodsId'               =>'账户充值',
            'goodsInfo'             =>'',
            'subMerNo'              =>'',
            'subMerName'            =>'',
            //Wap此字段不能为空，填写格式：{"s":"WAP","n":"WAP网站名","id":"WAP网站的首页URL"} 网址需保证公网可以正常访问。不参与签名
            'metaOption'            =>'',
            'fileId1'               =>'',
            'fileId2'               =>'',
            'fileId3'               =>'',
        ];
        $signParams = $params;
        $params['sign'] = self::md5Sign($signParams,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/gateway/unityOrder/doPay";
        $resTxt = self::post($requestUrl,$params);
       // $resTxt = self::buildForm($params,$requestUrl);

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (isset($res['respCode']) && $res['respCode'] == '000A' && !empty($res['qrcode'])) {
                $ret['status'] = Macro::SUCCESS;
                $res['qrcode'] = urldecode($res['qrcode']);
                if(Util::isMobileDevice() && substr($res['qrcode'],0,4)=='http'){
                    $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                    $ret['data']['url'] = $res['qrcode'];
                }else{
                    $ret['data']['type'] = self::RENDER_TYPE_QR;
                    $ret['data']['qr'] = $res['qrcode'];
                }
            } else {
                $ret['message'] = $res['respDesc']??'付款提交失败';
            }
        }

        return $ret;
    }
    /**
     * 京东扫码支付
     */
    public function jdQr()
    {
        if(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'version'               =>'1.0',
            'merNo'                 =>$this->order['channel_merchant_id'],
            'transTime'             =>date('YmdHis'),
            'orderNo'               =>$this->order['order_no'],
            'amount'                =>$this->order['amount'],
            'memberIp'              =>Yii::$app->request->remoteIP,
            'authCode'              =>'',
            'tradeType'             =>'1007',
            //页面通知地址。扫码选填，WAP必填
            'returnUrl'             =>'',
            'notifyUrl'             =>str_replace('https','http',$this->paymentConfig['paymentNotifyBaseUri'])."/gateway/v1/web/ltb/notify",
            'goodsId'               =>'账户充值',
            'goodsInfo'             =>'',
            'subMerNo'              =>'',
            'subMerName'            =>'',
            //Wap此字段不能为空，填写格式：{"s":"WAP","n":"WAP网站名","id":"WAP网站的首页URL"} 网址需保证公网可以正常访问。不参与签名
            'metaOption'            =>'',
            'fileId1'               =>'',
            'fileId2'               =>'',
            'fileId3'               =>'',
        ];
        $signParams = $params;
        $params['sign'] = self::md5Sign($signParams,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/gateway/unityOrder/doPay";
        $resTxt = self::post($requestUrl,$params);

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $resTxt, $params);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['respCode']) && $res['respCode'] == '000A' && !empty($res['qrcode'])) {
                $ret['status'] = Macro::SUCCESS;
                $res['qrcode'] = urldecode($res['qrcode']);
                if(Util::isMobileDevice() && substr($res['qrcode'],0,4)=='http'){
                    $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                    $ret['data']['url'] = $res['qrcode'];
                }else{
                    $ret['data']['type'] = self::RENDER_TYPE_QR;
                    $ret['data']['qr'] = $res['qrcode'];
                }
            } else {
                $ret['message'] = $res['respDesc']??'付款提交失败';
            }
        }
        return $ret;
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){
        $params = [
            'version'                   =>'1.0',
            'merNo'                     =>$this->order['channel_merchant_id'],
            'orderNo'                   =>$this->order['order_no'],
            'tradeType'                 =>1,
            'fileId1'                   =>'',
            'fileId2'                   =>'',
            'fileId3'                   =>'',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/gateway/query/doQueryOrder";
        $resTxt = self::post($requestUrl,$params);
        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['respCode']) && $res['respCode'] == '0000' && isset($res['status']) && isset($res['status'])=='100' && !empty($res['amount'])) {
                $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                if($localSign == $res['sign']){
                    $ret['status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['amount'];
                }
            } else {
                $ret['message'] = $res['respDesc']??'订单查询失败';
            }
        }

        return  $ret;
    }

    /**
     * 余额查询,此通道没有余额查询接口.但是需要做伪方法,防止批量实时查询失败.
     *
     * return  array BasePayment::BALANCE_QUERY_RESULT
     */
    public function balance()
    {
        //暂时未知查询tradeType的值
        return true;

        $params = [
            'version'                   =>'1.0',
            'merNo'                     =>$this->order['channel_merchant_id'],
            'orderNo'                   =>date('Ymdhis').mt_rand(10000,99999),
            'tradeType'                 =>1,
            'fileId1'                   =>'',
            'fileId2'                   =>'',
            'fileId3'                   =>'',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/gateway/query/doQueryBalance";
        $resTxt = self::post($requestUrl,$params);
        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::BALANCE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['respCode']) && $res['respCode'] == '0000' && isset($res['status']) && isset($res['status'])=='100' && !empty($res['amount'])) {
                $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                if($localSign == $res['sign']){
                    $ret['status'] = Macro::SUCCESS;
                    $ret['data']['balance'] = $res['amount'];
                }
            } else {
                $ret['message'] = $res['respDesc']??'订单查询失败';
            }
        }

        return  $ret;
    }

    /**
     * 提交出款请求
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remit(){
        $ret = self::REMIT_RESULT;

        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code'],'remit');
        $bank_code = [
            '3003'      => 'CCB',
            '3005'      => 'ABC',
            '3002'      => 'ICBC',
            '3026'      => 'BOC',
            '3004'      => 'SPDB',
            '3022'      => 'CEB',
            'PABC'      => 'PABC',
            '3009'      => 'CIB',
            '3038'      => 'PSBC',
            '3039'      => 'CITIC',
            '3050'      => 'HXB',
            '3001'      => 'CMB',
            '3036'      => 'GDB',
            'BCCB'      => 'BCCB',
            'SHB'       => 'SHB',
        ];
        $bankCode = $bank_code[$bankCode];
        if(empty($bankCode)){
            throw new OperationFailureException("银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        if(empty($this->remit['bank_branch'])){
//            throw new OperationFailureException("银行卡开户网点不能为空！",Macro::ERR_PAYMENT_BANK_CODE);
            $this->remit['bank_branch'] = $this->remit['bank_name'].'北京市中关村分行';
        }

        $params = [
            'version'                   => '1.0',
            'merNo'                     => $this->remit['channel_merchant_id'],
            'transTime'                 => date('YmdHis'),
            'orderNo'                   => $this->remit['order_no'],
            'transTime'                 => date('YmdHis'),
            'tradeType'                 => '1005',
            'amount'                    => $this->remit['amount'],
            'acctType'                  => '1',
            'acctName'                  => self::aesEncrypt($this->remit['bank_account'],$this->paymentConfig['encrypt_key']),
            'acctNo'                    => self::aesEncrypt($this->remit['bank_no'],$this->paymentConfig['encrypt_key']),
            'bankName'                  => '',
            'bankCode'                  => $bankCode,
            'province'                  => '',
            'city'                      => '',
            'bankSettNo'                => '',
            'cardNo'                    => '',
            'mobileNo'                  => '',
            'fileId1'                   => '',
            'fileId2'                   => '',
            'fileId3'                   => '',
        ];
        //备注：notify_url, variables 不参与签名！！！
        $signParams = $params;
        $params['sign'] = self::md5Sign($signParams, trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/gateway/singlePay/doPay';
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, $params);
        Yii::info('remit to bank raw result: '.$this->remit['order_no'].' '.$resTxt);
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
            Yii::info($this->remit['order_no'].'remit ret localSign '.$localSign.' remote sign:'.$res['sign']);
            if (isset($res['respCode']) && $res['respCode'] == '0000') {
                $ret['msg'] = !empty($res['respDesc'])?Util::unicode2utf8($res['respDesc']):"出款提交失败({$resTxt})";
                $ret['status'] = Macro::SUCCESS;
            } else {
                $ret['msg'] = !empty($res['respDesc'])?Util::unicode2utf8($res['respDesc']):"出款提交失败({$resTxt})";
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
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $params = [
            'version'                   =>'1.0',
            'merNo'                     =>$this->remit['channel_merchant_id'],
            'orderNo'                   =>$this->remit['order_no'],
            'tradeType'                 =>2,
            'fileId1'                   =>'',
            'fileId2'                   =>'',
            'fileId3'                   =>'',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/gateway/query/doQueryOrder";
        $resTxt = self::post($requestUrl, $params);
        Yii::info('order query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);
            if (isset($res['respCode']) && $res['respCode'] == '0000' && isset($res['status']) && isset($res['status'])=='100' && !empty($res['amount'])) {
                $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                if($localSign == $res['data']['sign']){
                    $ret['status'] = Macro::SUCCESS;
                    $ret['data']['amount'] = $res['amount'];
                }
            } else {
                $ret['message'] = $res['respDesc']??'订单查询失败';
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
                if(!empty($value))
                $params[] = "$key=$value";
            }
            sort($params,SORT_STRING);
            $params = implode('&', $params);
        } elseif (is_string($params)) {

        } else {
            return false;
        }

        $signStr = strtoupper(md5($params.'&key='.$signKey));
        Yii::info('md5Sign string: '.$signStr.' raw: '.$params.'&key='.$signKey);
        return $signStr;
    }
}