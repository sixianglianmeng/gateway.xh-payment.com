<?php
$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../base.php',
    [
        'bootstrap' => [],
        'modules' => [],
        'components' => [
            'db' => [
                'class' => 'yii\db\Connection',
                'dsn' => 'mysql:host=127.0.0.1;dbname=xh_payment_com',
                'username' => 'xh_payment_com',
                'password' => 'xf8LxyLRZmNM62Jd',
                'charset' => 'utf8',
                'tablePrefix' => 'p_',
                'enableLogging'=>true,
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
                        'enableRotation' => true,
                        'maxFileSize' => 1024 * 100,
                        'logVars' => [],
                    ],
                ],
            ],
        ],
        'params' => [
        ],
    ]
);

return $config;