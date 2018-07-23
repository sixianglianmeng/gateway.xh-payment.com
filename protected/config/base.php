<?php
!defined('SYSTEM_NAME') && define('SYSTEM_NAME', 'gateway_pub_payment');
//redis key前缀，用于在同一个redis实例部署多套相同程序时使用
!defined('REDIS_PREFIX') && define('REDIS_PREFIX', 'gp_');
!defined('WWW_DIR') && define('WWW_DIR', realpath(__DIR__ . '/../../'));
!defined('RUNTIME_DIR') && define('RUNTIME_DIR', WWW_DIR . '/runtime');
//!is_dir(RUNTIME_DIR) && mkdir(RUNTIME_DIR, 0777, true);

$config = [
    'id'        => SYSTEM_NAME,
    'basePath'  => __DIR__.DIRECTORY_SEPARATOR.'..',
    'name'      => SYSTEM_NAME,
    'bootstrap' => [
        'log',
        'paymentNotifyQueue',
        'remitBankCommitQueue',
        'remitQueryQueue',
        'orderQueryQueue',
        'remitNotifyQueue',
    ],
    'runtimePath' => constant('RUNTIME_DIR'),
    'modules' => [
        'gateway' => [
            'class' => 'app\modules\gateway\GatewayModule',
        ],
    ],
    'components' => [
        'response' => [
            'format'    => yii\web\Response::FORMAT_JSON,
        ],
        'request'=>[
            'enableCookieValidation' => false,
            'enableCsrfValidation'   => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ]
        ],
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis' => 'redis'
        ],
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'mysql:host=35.229.128.154;dbname=xh_payment_com',
            'username' => 'xh_payment_com',
            'password' => 'xf8LxyLRZmNM62Jd',
            'charset' => 'utf8',
            'tablePrefix' => 'p_',
//            'enableLogging'=>true,
        ],
        'redis' => [
            'class' => 'yii\redis\Connection',
            'hostname' => '127.0.0.1',
            'port' => 6381,
            'database' => 0,
        ],
        'formatter' => [
            'dateFormat' => 'yyyy-mm-dd',
            'datetimeFormat' => 'yyyy-mm-dd H:i:s',
            'decimalSeparator' => ',',
            'thousandSeparator' => ' ',
            'currencyCode' => 'RMB',
        ],
        'user' => [
            'identityClass' => '\app\common\models\model\User',
            'class' => 'yii\web\User',
            'enableAutoLogin' => true,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'urlManager' => [
            'class'     => '\yii\web\UrlManager',
            'enablePrettyUrl' => true,
            'showScriptName'  => false,

//            'enableStrictParsing' => true,
            'rules' => [
                /********商户接口URL重写开始*******/
                //收银台
                '/cashier.html' => '/gateway/v1/web/order/cashier',
                '/api/v1/cashier' => '/gateway/v1/web/order/cashier',
                //订单付款
                '/order/pay.html' => '/gateway/v1/web/order-pay/pay',
                //下单后随机跳转多次再到上游
                '/order/go.html' => '/gateway/v1/web/order-pay/rand-redirect',
                '/order/go/<sign:\S+>.html' => '/gateway/v1/web/order-pay/rand-redirect',
                //扫码界面循环检测订单状态
                '/order/check_status.html' => '/gateway/v1/web/order-pay/check-status',
                //v1支付接口
                '/pay.html' => '/gateway/v1/web/order/web-bank',
                //后台下单接口
                '/order.html' => '/gateway/v1/server/order/order',
                '/api/v1/order' => '/gateway/v1/server/order/order',
                //收款查询
                '/query.html' => '/gateway/v1/server/order/status',
                '/api/v1/query' => '/gateway/v1/server/order/status',
                //出款
                '/remit.html' => '/gateway/v1/server/remit/single',
                '/api/v1/remit' => '/gateway/v1/server/remit/single',
                //出款查询
                '/remit_query.html' => '/gateway/v1/server/remit/status',
                '/api/v1/remit_query' => '/gateway/v1/server/remit/status',
                //余额查询
                '/balance.html' => '/gateway/v1/server/account/balance',
                '/api/v1/balance' => '/gateway/v1/server/account/balance',

                //充值回调
                '/api/v1/callback/recharge-notify/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-notify',
                '/api/v1/callback/recharge-return/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-return',
                '/api/v1/callback/remit-notify/<channelId:\d+>' => '/gateway/v1/web/callback/remit-notify',
                /********商户接口URL重写结束*******/

                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => ['api/v1/user'],
                    'pluralize' => true,
                    'extraPatterns' => [
                        'POST login' => 'login',
                        'GET signup-test' => 'signup-test',
                        'GET profile' => 'profile',
                    ]
                ],
            ]
        ],
        'log' => [
            'targets' => [
                'file' => [
                    'class' => '\power\yii2\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    'logFile' => '@runtime/log/err'.date('md').'.log',
                    'enableRotation' => true,
                    'maxFileSize' => 1024 * 100,
                    'logVars' => [],
                ],
                'notice' => [
                    'class' => '\power\yii2\log\FileTarget',
                    'levels' => ['notice', 'trace','info','warning','error'],//'profile',
                    'logFile' => '@runtime/log/common'.date('md').'.log',
//                    'categories' => ['yii\db\Command::query', 'yii\db\Command::execute'],
                    'enableRotation' => true,
                    'maxFileSize' => 1024 * 100,
                    'logVars' => [],
                    'prefix' => function ($message) {
                        $request = Yii::$app->getRequest();
                        $ip = method_exists($request,'getUserIP')?$request->getUserIP() : '-';

                        $user = Yii::$app->has('user', true) ? Yii::$app->get('user') : null;
                        if ($user && ($identity = $user->getIdentity(false))) {
                            $userID = $identity->getId();
                        } else {
                            $userID = '-';
                        }

                        if (empty($_SERVER['LOG_ID']) || !is_string($_SERVER['LOG_ID'])) {
                            $_SERVER['LOG_ID'] = strval(uniqid());
                        }

                        return "[$ip] [$userID] [{$_SERVER['LOG_ID']}]";
                    }
                ],
                'db_log' => [
                    'levels' => ['warning','error'],
                    'class' => '\yii\log\DbTarget',
                    'exportInterval' => 1,
                    'logVars' => [],
                    'logTable' => '{{%system_log}}',
                ],

                'sys_notice'=>[
                    'class' => 'app\components\SystemNoticeLogger',
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    //电报报警，会传入msg=xx&key=xx&chatId=xx到api_uri对应接口
                    //配置已移到系统配置表
                    'telegram'=>[],
                    //邮件报警
		            //配置已移到系统配置表
                    'email' => [],
                ],
            ],
        ],
        'mailer' => [
            'class' => 'yii\swiftmailer\Mailer',
//            'viewPath' => '@app/mail',
            'useFileTransport' =>false,//这句一定有，false发送邮件，true只是生成邮件在runtime文件夹下，不发邮件
            'transport' => [
                'class' => 'Swift_SmtpTransport',
                'encryption' => 'tls',
                'host' => 'smtp.gmail.com',
                'port' => '587',
                'username' => 'mail.booter.ui@gmail.com',
                'password' => 'htXb7wyFhDDEu74Y',
            ],
            'messageConfig'=>[
                'charset'=>'UTF-8',
                'from'=>['mail.booter.ui@gmail.com'=>'支付网关']
            ],
        ],
        'i18n' => [
            'translations' => [
                'app*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    //'basePath' => '@app/messages',
                    //'sourceLanguage' => 'en-US',
                    'language' => 'zh-CN',
                    'fileMap' => [
                        'app' => 'app.php',
                        'app/error' => 'error.php',
                    ],
                ],
            ],
        ],
        'paymentNotifyQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_on',
//            'strictJobType' => false,
//            'serializer' => \yii\queue\serializers\JsonSerializer::class,
        ],
        'remitBankCommitQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_rbc',
        ],
        'remitQueryQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_rq',
        ],
        'orderQueryQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_oq',
        ],
	    'remitNotifyQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_rnq',
        ],

        'on beforeRequest' => ['\power\yii2\log\LogHelper', 'onBeforeRequest'],
        'on afterRequest' => ['\power\yii2\log\LogHelper', 'onAfterRequest'],
    ],

    'params' => [
        'secret'   => [        // 参数签名私钥, 由客户端、服务端共同持有
        ],

        'paymentGateWayApiDefaultSignType' => 'md5',//rsa

        'user.apiTokenExpire' => 3600,
        'user.passwordResetTokenExpire' => 600,
        'user.rateLimit' => [60, 60],
    ],
];

return $config;
