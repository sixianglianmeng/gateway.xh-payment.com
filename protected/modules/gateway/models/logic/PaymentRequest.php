<?php

namespace app\modules\gateway\models\logic;

use app\common\models\model\User;
use app\common\models\model\UserBlacklist;
use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use app\components\Util;
use power\yii2\exceptions\ParameterValidationExpandException;
use Yii;
use yii\db\Query;
use yii\web\Cookie;

class PaymentRequest
{
    const SUCCESS = API_RESP_CODE_SUCCESS;
    const FAIL = API_RESP_CODE_FAIL;
    const DEFAULT_JSON_RESPONSE = [
        API_RESP_FIELD_CODE => self::FAIL,
        API_RESP_FIELD_SIGN => '',
        API_RESP_FIELD_MESSAGE => '',
    ];
    const CLIENT_ID_IN_COOKIE = 'x_client_id';

    protected $merchant = null;
    protected $merchantPayment = null;

    //参数名=>[参数类型，参数校验附加参数,是否为可选参数]
    const REQUEST_PARAM_RULES = [
        'merchant_code'  => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32]],
        'order_no'       => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32]],
        'pay_type'       => [Macro::CONST_PARAM_TYPE_PAYTYPE, []],
        'bank_code'      => [Macro::CONST_PARAM_TYPE_STRING, [0, 32], true],//[Macro::CONST_PARAM_TYPE_BANKCODE, [], true],
        'order_amount'   => [Macro::CONST_PARAM_TYPE_DECIMAL, [1, 32]],
        'order_time'     => [Macro::CONST_PARAM_TYPE_INT],
        'query_time'     => [Macro::CONST_PARAM_TYPE_INT],
        'req_referer'    => [Macro::CONST_PARAM_TYPE_STRING, [0, 255], true],
        'customer_ip'    => [Macro::CONST_PARAM_TYPE_IPv4, [], true],
        'notify_url'     => [Macro::CONST_PARAM_TYPE_STRING, [1, 255]],
        'return_url'     => [Macro::CONST_PARAM_TYPE_STRING, [1, 255]],
        'return_params'  => [Macro::CONST_PARAM_TYPE_STRING, [0, 255]],
        'sign'           => [Macro::CONST_PARAM_TYPE_ALNUM, [32, 32]],
        'nonce'          => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 64]],
        'trade_no'       => [Macro::CONST_PARAM_TYPE_ALNUM_DASH_UNDERLINE, [1, 32]],
        'now_date'       => [Macro::CONST_PARAM_TYPE_INT],
        'account_name'   => [Macro::CONST_PARAM_TYPE_STRING, [1, 32]],
        'account_number' => [Macro::CONST_PARAM_TYPE_NUMERIC_STRING, [10, 32]],
        'bank_province'  => [Macro::CONST_PARAM_TYPE_STRING, [0, 32], true],
        'bank_city'      => [Macro::CONST_PARAM_TYPE_STRING, [0, 32], true],
        'bank_branch'    => [Macro::CONST_PARAM_TYPE_STRING, [0, 32], true],
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

            //允许为空且没有的,校验通过
            if(!empty($rule[2]) && !isset($allParams[$p])){
                $valid = true;
            }

            if (true !== $valid) {
                $msg = "参数格式校验失败({$p},{$allParams[$p]})";
                throw  new ParameterValidationExpandException($msg);
                return false;
            }
        }

        //终端用户请求web接口，检测用户是否在黑名单
        if(substr(Yii::$app->controller->id,2,5)=='/web/'){
            //检测用户或者IP是否在黑名单中
            if(!self::checkBlackListUser()){
                $msg = '对不起，IP网络安全检测异常，暂时无法提供服务:'.Macro::ERR_USER_BAN;
                throw  new ParameterValidationExpandException($msg);
            }

            //检测referer
            if(!self::checkReferrer($this->merchantPayment)){
                $msg = '对不起，来路域名错误，请联系您的商户:'.Macro::ERR_REFERRER;
                throw  new ParameterValidationExpandException($msg);
            }
        }

        return true;

    }


    static public function checkBlackListUser(){
        $ip = Util::getClientIp();

        $query = (new Query())->select('id')->from(UserBlacklist::tableName())
            ->where(['and','type=1',"val='".$ip."'"]);

        $cookies = Yii::$app->request->cookies;
        $clientId = self::getClientId();
        if ($clientId){
            $where[] = ' OR (type=1 AND val='.$clientId.')';
            $query->orWhere(['and','type=2',"val='{$clientId}'"]);
        }

        $black = $query->one();
        if($black){
            return false;
        }

        return true;
    }

    static public function getClientId(){
        $cookies = Yii::$app->request->cookies;

        return $cookies[self::CLIENT_ID_IN_COOKIE]??'';
    }

    /**
     * 根据商户配置的来路域名检测来源referrer
     *
     * @param UserPaymentInfo $merchantPayment
     * @return bool
     *
     */
    static public function checkReferrer(UserPaymentInfo $merchantPayment){
        $domains = $merchantPayment->app_server_domains;
        $referer = Yii::$app->request->referrer;
        $refDomain = parse_url($referer,PHP_URL_HOST);

        if($domains && strpos($domains,$refDomain)!==false){
            return false;
        }

        return true;
    }

    public static function setClientIdCookie(){
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
}
