<?php

namespace app\components;
use app\lib\helpers\ResponseHelper;
use power\yii2\helpers\HttpCallHelper;
use Yii;

/*
 * 后端支付平台接口，直接调用即可，成功返回json中data数据，不成功抛异常
 * $ret = RpcPaymentGateway::syncRechargeOrderStatus(1800)
 */
class RpcPaymentGateway
{
    /**
     * 强制到第三方查询同步充值订单状态
     *
     * @param int $inSeconds 多少小时之内的
     * @param array $orderNoList 要同步的订单号列表
     * @return array
     */
    public static function syncRechargeOrderStatus($inSeconds = 1800, $orderNoList = null)
    {
        $ret = self::call('/order/sync-status',['inSeconds'=>$inSeconds,'orderNoList'=>$orderNoList]);

        return $ret;
    }

    /**
     * rpc访问接口
     *
     * @param string 接口路径
     * @param array 接口参数
     * @return
     */
    public static function call($path, $params)
    {

        $params['_nonce_'] = Util::uuid();
        $params['_sign_'] = md5(Yii::$app->params['secret']['agent.payment'].$params['_nonce_']);

        $header = [
            'Content-Type: application/json',
            'HTTP_X_RPC_KEY: '.$params['_sign_']
        ];
        $strUrl = Yii::$app->params['domain.gateway.rpc'] . '/gateway/v1/inner';

        try {
            $ret = HttpCallHelper::execHttpCall($strUrl.$path, json_encode($params), HttpCallHelper::POST,5000,[],$header);
            return $ret;
        } catch (\Exception $ex) {
            Yii::warning('rpc. exception: ' . $ex->getMessage());
            return [];
        }
    }
}
