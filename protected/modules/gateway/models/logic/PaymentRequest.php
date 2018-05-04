<?php

namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\model\UserPaymentInfo;
use Yii;
use yii\db\Query;
use app\common\models\model\UserBlacklist;
use app\components\Macro;
use app\components\Util;
use app\common\models\model\User;
use power\yii2\exceptions\ParameterValidationExpandException;
use yii\web\Cookie;

class PaymentRequest
{
    const SUCCESS = 'TRUE';
    const FAIL = 'FALSE';
    const JSON_STATUS_KEY = 'is_success';
    const DEFAULT_JSON_RESPONSE = [
        'is_success' => self::FAIL,
        'sign'       => '',
        'error_msg' => '',
    ];
    const CLIENT_ID_IN_COOKIE = 'x_client_id';

    protected $merchant = null;
    protected $merchantPayment = null;

    //参数名=>[参数类型，参数校验附加参数,是否为可选参数]
    const REQUEST_PARAM_RULES = [
        'merchant_code'  => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32]],
        'order_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32]],
        'pay_type'             => [Macro::CONST_PARAM_TYPE_PAYTYPE, []],
        'bank_code'            => [Macro::CONST_PARAM_TYPE_BANKCODE, [], true],
        'order_amount'         => [Macro::CONST_PARAM_TYPE_DECIMAL, [1, 32]],
        'order_time'           => [Macro::CONST_PARAM_TYPE_DATETIME],
        'req_referer'          => [Macro::CONST_PARAM_TYPE_STRING, [1, 255]],
        'customer_ip'          => [Macro::CONST_PARAM_TYPE_IPv4, [], true],
        'notify_url'           => [Macro::CONST_PARAM_TYPE_STRING, [1, 255]],
        'return_url'           => [Macro::CONST_PARAM_TYPE_STRING, [1, 255]],
        'return_params'        => [Macro::CONST_PARAM_TYPE_STRING, [0, 255]],
        'sign'                 => [Macro::CONST_PARAM_TYPE_ALNUM, [32, 32]],
        'nonce'                => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 64]],
        'trade_no'             => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32]],
        'now_date'             => [Macro::CONST_PARAM_TYPE_DATETIME, [1, 32]],
        'account_name'         => [Macro::CONST_PARAM_TYPE_STRING, [1, 32]],
        'account_number'       => [Macro::CONST_PARAM_TYPE_NUMBERIC_STRING, [10, 32]],
    ];

    public function __construct(User $merchant,UserPaymentInfo $merchantPayment)
    {
        $this->merchant = $merchant;
        $this->merchantPayment = $merchantPayment;
    }

    /**
     * 校验支付请求
     *
     * @param array $allParams 所有请求参数
     * @param array $needParams 需要校验得出参数名列表
     * @param array $rules 校验参数，参见PaymentRequest::REQUEST_PARAM_RULES
     * @return boolean|string
     */
    public function validate(array $allParams, array $needParams, $rules = [])
    {
        foreach ($needParams as $p) {
            $valid = false;
            $rule = self::REQUEST_PARAM_RULES[$p]??($rules[$p]??null);
            if (
                !empty($allParams[$p])
                && !empty($rule)
            ) {
                $valid = Util::validate(
                    $allParams[$p],
                    $rule[0], $rule[1] ?? null
                );
            }

            if ( !empty($allParams[$p]) && empty($rule[2]) && true !== $valid) {
                $msg = '参数格式校验失败(' . $p . ':' . ($allParams[$p] ?? '') . json_encode($valid) . ')';
                throw  new ParameterValidationExpandException($msg);
                return false;
            }
        }

        //检测用户或者IP是否在黑名单中
        if(!$this->checkBlackListUser()){
            $msg = '对不起，银行安全检测异常，暂时无法充值:'.Macro::ERR_USER_BAN;
            throw  new ParameterValidationExpandException($msg);
        }

        //检测referer
        if(!$this->checkReferrer()){
            $msg = '对不起，来路域名错误，请联系您的商户:'.Macro::ERR_REFERRER;
            throw  new ParameterValidationExpandException($msg);
        }

        return true;

    }


    static public function checkBlackListUser(){
        $ip = Yii::$app->request->userIP;


        //['OR', 'type=1', ['AND', 'id=1', 'id=2']]
//        $where  = ' OR (type=1 AND val='.$cookies[self::CLIENT_ID_IN_COOKIE].')';
        $query = (new Query())->select('id')->from(UserBlacklist::tableName())
            ->where(['and','type=1',"val='".$ip."'"]);

        $cookies = Yii::$app->request->cookies;
        if (isset($cookies[self::CLIENT_ID_IN_COOKIE])){
            $where[] = ' OR (type=1 AND val='.$cookies[self::CLIENT_ID_IN_COOKIE].')';
            $query->orWhere(['and','type=2',"val='{$cookies[self::CLIENT_ID_IN_COOKIE]}'"]);
        }

        $black = $query->one();
        if($black){
            return false;
        }

        return true;
    }

    public function checkReferrer(){
        $domains = $this->merchantPayment->app_server_domains;
        $referer = Yii::$app->request->referrer;
        $refDomain = parse_url($referer,PHP_URL_HOST);

        if($domains && strpos($domains,$refDomain)!==false){
            return false;
        }

        return true;
    }

    public function setClientIdCookie(){
        $cookies = Yii::$app->request->cookies;
        if(empty($cookies[self::CLIENT_ID_IN_COOKIE])){
            $resCookies = Yii::$app->response->cookies;
            $resCookies->add(new Cookie([
                'name' => self::CLIENT_ID_IN_COOKIE,
                'value' => Util::uuid(),
                'expire' => time()+86400*3650,
                'path' => '/'
            ]));
        }
    }


    public function getPaymentChannelAccount(){
        if(empty($this->merchantPayment)){
            throw new \Exception('订单信息错误',Macro::ERR_PAYMENT_CHANNEL_ACCOUNT);
        }

        return $this->merchantPayment->channelAccount;
    }
}
