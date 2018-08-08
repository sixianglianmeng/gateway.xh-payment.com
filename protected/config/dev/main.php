<?php
$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../base.php',
    [
        'bootstrap' => [],
        'modules' => [],
        'components' => [
            'log' => [
                'targets' => [
                    'db'=>[
                        'class' => '\power\yii2\log\FileTarget',
                        'logFile' => '@runtime/log/common.log',
                        'logVars' => [],
                        'levels' => ['info','profile'],
                        'categories' => ['yii\db\Command::query', 'yii\db\Command::execute'],
                        'prefix' => function($message) {
                            return '';
                        },
                        'enabled' => false,
                    ],
                ],
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
        ],
        'params'    => [],
    ]
);

if(YII_ENV_DEV) {
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        'allowedIPs' => ['127.0.0.1', '::1','192.168.1.*'] // adjust this to your needs
    ];
}

return $config;
