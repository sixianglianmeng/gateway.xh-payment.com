<?php
namespace app\lib\helpers;

use Yii;
use power\yii2\web\Response;

class ResponseHelper extends \power\yii2\helpers\ResponseHelper
{
    /**
     * 输出格式化.
     * 
     * @param array     $data
     * @param string    $message
     * @param integer   $code
     * @param string    $callback
     * @param string    $format     返回数据类型, 默认使用Reponse::FORMAT_JSON
     * @return array
     */
    public static function formatOutput( $code = 200, $message = '', $data = [], $callback = null)
    {
        $callback = self::getCallback($callback);
        self::$status = $code;

        $response = Yii::$app->getResponse();
        $result = [
            'data'          => $data,
            'code'        => $code,
            'message'       => $message,
            'serverTime'    => microtime(true),
        ];

        Yii::pushLog('status', $code);
        if ($response->format == Response::FORMAT_HTML) {
//            $result['return_url'] = Yii::$app->request->referrer;
            $result = Yii::$app->controller->render('@app/modules/gateway/views/default/error',$result);
        }elseif ($response->format == Response::FORMAT_JSONP) {
            $result = [
                'data'      => $result,
                'callback'  => $callback,
            ];
        }

        return $result;
    }
}
