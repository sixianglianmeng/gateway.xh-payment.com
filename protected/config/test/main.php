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
        ],
        'params' => [
            'domain.cdn' => 't1.agent.huaruipay.com',
            'domain.gateway.rpc' => 't1.gateway.huaruipay.com',
            'corsOriginDomain' => ['*','t1.gateway.huaruipay.com'],
        ],
    ]
);

return $config;
