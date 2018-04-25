<?php
!defined('SYSTEM_NAME') && define('SYSTEM_NAME', 'yii2');
!defined('WWW_DIR') && define('WWW_DIR', realpath(__DIR__ . '/../../'));
!defined('RUNTIME_DIR') && define('RUNTIME_DIR', WWW_DIR . '/runtime');
//!is_dir(RUNTIME_DIR) && mkdir(RUNTIME_DIR, 0777, true);

$config = [
    'id'        => SYSTEM_NAME,
    'basePath'  => __DIR__.DIRECTORY_SEPARATOR.'..',
    'name'      => SYSTEM_NAME,
    'bootstrap' => [
        'log'
    ],
    'runtimePath' => constant('RUNTIME_DIR'),
    'modules' => [
        'api' => [
            'class' => 'app\modules\api\ApiModule',
        ],
        'gateway' => [
            'class' => 'app\modules\gateway\GatewayModule',
        ],
    ],
    'components' => [
        'response' => [
            'format'    => yii\web\Response::FORMAT_JSON,
            //统一返回数据格式可迁移至模块中Module::init()
//            'on beforeSend' => function ($event) {
//                $response = $event->sender;
//                $response->data = [
//                    'code' => $response->getStatusCode(),
//                    'data' => $response->data,
//                    'message' => $response->statusText
//                ];
//                $response->format = yii\web\Response::FORMAT_JSON;
//            },
        ],
        'request'=>[
            'enableCookieValidation' => false,
            'enableCsrfValidation'   => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ]
        ],
        'cache' => [
            'class'     => 'yii\caching\FileCache',
            'cachePath' => '@runtime/cache.dat',
        ],
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'mysql:host=127.0.0.1;dbname=lt_payment',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8',
            'tablePrefix' => 'p_',
//            'enableLogging'=>true,
        ],
        'redis' => [
            'class' => 'yii\redis\Connection',
            'hostname' => '127.0.0.1',
            'port' => 6379,
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
                [
                    'pattern' => 'MP_verify_<mp:\w+>',
                    'route' => 'wx/wechat/mp',
                    'suffix' => '.txt',
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => ['api/v1/Wechatcustomer'],
                    'pluralize' => true,
                ],
            ]
        ],
        'log' => [
            'targets' => [
                'file' => [
                    'class' => '\power\yii2\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    'logFile' => '@runtime/log/err.log',
                    'enableRotation' => true,
                    'maxFileSize' => 1024 * 100,
                    'logVars' => [],
                ],
                'notice' => [
                    'class' => '\power\yii2\log\FileTarget',
                    'levels' => ['notice', 'info'],//'profile',
                    'logFile' => '@runtime/log/common.log',
                    'enableRotation' => true,
                    'maxFileSize' => 1024 * 100,
                    'logVars' => [],
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
                'host' => 'smtp.google.com',
                //阿里云连接必须使用80端口
                'port' => '80',
                'username' => 'webmaster@payment.com',
                'password' => '',
//                'fromName' => '',
//                'fromMail' => 'webmaster@payment.com',
            ],
            'messageConfig'=>[
                'charset'=>'UTF-8',
                'from'=>['webmaster@payment.com'=>'支付网关']
            ],
        ],
        'i18n' => [
            'translations' => [
                'app*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    //'basePath' => '@app/messages',
                    'sourceLanguage' => 'en-US',
                    'language' => 'zh-CN',
                    'fileMap' => [
                        'app' => 'app.php',
                        'app/error' => 'error.php',
                    ],
                ],
            ],
        ],
        'on beforeRequest' => ['\power\yii2\log\LogHelper', 'onBeforeRequest'],
        'on afterRequest' => ['\power\yii2\log\LogHelper', 'onAfterRequest'],
    ],

    'params' => [
        'secrect'   => [        // 参数签名私钥, 由客户端、服务端共同持有
            'test'   => 'e09813f8015339fc445f3a84bb8c4023',
        ],

        'user.apiTokenExpire' => 3600,
        'user.passwordResetTokenExpire' => 600,
        'user.rateLimit' => [60, 60],
        'domain.cdn' => 'dev.gateway.payment.com',
        'corsOriginDomain' => ['*','dev.gateway.payment.com'],
    ],
];

return $config;
