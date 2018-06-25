<?php
$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../base.php',
    [
        'bootstrap' => [],
        'modules' => [],
        'components' => [
            'db' => [
                'class' => 'yii\db\Connection',
                'dsn' => 'mysql:host=127.0.0.1;dbname=payment_com',
                'username' => 'payment_com',
                'password' => '3MLNH3tatiXSKzr8',
                'charset' => 'utf8',
                'tablePrefix' => 'p_',
                'enableLogging'=>true,
            ],
            'redis' => [
                'class' => 'yii\redis\Connection',
                'hostname' => '127.0.0.1',
                'port' => 63780,
                'database' => 0,
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
            'domain.cdn' => 't1.agent.gd95516.com',
            'domain.gateway' => 't1.gateway.gd95516.com',
            'domain.gateway.rpc' => 't1.gateway.gd95516.com',
            'corsOriginDomain' => ['*','t1.gateway.gd95516.com'],
        ],
    ]
);

return $config;