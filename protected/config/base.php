<?php
!defined('SYSTEM_NAME') && define('SYSTEM_NAME', 'gateway_payment');
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
        'orderQueryQueue'
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
//            'class'     => 'yii\caching\FileCache',
//            'cachePath' => '@runtime/cache.dat',
            'class' => 'yii\redis\Cache',
            'redis' => 'redis'
        ],
        'db' => [
            'class' => 'yii\db\Connection',
//            'dsn' => 'mysql:host=127.0.0.1;dbname=lt_payment',
            'dsn' => 'mysql:host=35.201.165.143;dbname=payment_com',
//            'username' => 'root',
            'username' => 'payment',
//            'password' => '',
            'password' => 'jWyd2pdHKqWAc7FF',
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
                    'levels' => ['notice', 'trace','info','warning','error'],//'profile',
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
                'host' => 'ssl://smtp.gmail.com:465',//ssl://smtp.gmail.com:465
                //阿里云连接必须使用80端口
                'port' => '465',
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
                    'sourceLanguage' => 'en-US',
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
        'on beforeRequest' => ['\power\yii2\log\LogHelper', 'onBeforeRequest'],
        'on afterRequest' => ['\power\yii2\log\LogHelper', 'onAfterRequest'],
    ],

    'params' => [
        'secret'   => [        // 参数签名私钥, 由客户端、服务端共同持有
            'test'          => 'e09813f8015339fc445f3a84bb8c4023',
            'agent.payment' => '736a0658e8a20f70ba5e53dc1ae9dc9f',
        ],

        'paymentGateWayApiDefaultSignType' => 'md5',//rsa

        'user.apiTokenExpire' => 3600,
        'user.passwordResetTokenExpire' => 600,
        'user.rateLimit' => [60, 60],
        'domain.cdn' => 'dev.gateway.payment.com',
        'domain.gateway.rpc' => 'dev.gateway.payment.com',
        'corsOriginDomain' => ['*','dev.gateway.payment.com'],
    ],
];

return $config;
