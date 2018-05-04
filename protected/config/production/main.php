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
//            'enableLogging'=>true,
            ],
            'redis' => [
                'class' => 'yii\redis\Connection',
                'hostname' => '127.0.0.1',
                'port' => 63780,
                'database' => 0,
            ],
        ],
        'params'    => [
            'domain.mall' => 'mall.payment.com',
            'domain.websocket' => 'pj.payment.com',
            'domain.cdn' => 'pj.payment.com',
        ]
    ]
);

$config['components']['log']['targets'][] = [
    'class' => 'yii\log\EmailTarget',
    'mailer' => 'mailer',
    'levels' => ['error', 'warning'],
    'message' => [
        'from' => ['webmaster@payment.com'],
        'to' => ['master@payment.com'],
        'subject' => '系统异常',
    ],
];

return $config;