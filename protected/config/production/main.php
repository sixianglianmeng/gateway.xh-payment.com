<?php
$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../base.php',
    [
        'bootstrap' => [],
        'modules' => [],
        'components' => [
            'db' => [
                'class' => 'yii\db\Connection',
                'dsn' => 'mysql:host=127.0.0.1;dbname=suhui_paymemt_com',
                'username' => 'suhui_paymemt',
                'password' => 'z4wIx7wouBOJi90A',
                'charset' => 'utf8',
                'tablePrefix' => 'p_',
                'enableSchemaCache' => true,
                // Name of the cache component used to store schema information
                'schemaCache' => 'cache',
                // Duration of schema cache.
                'schemaCacheDuration' => 86400, // 24H it is in seconds
//
//                'slaveConfig' => [
//                    'username' => 'suhui_paymemt',
//                    'password' => 'z4wIxIJtgH7wouBO',
//                    'attributes' => [
//                        PDO::ATTR_TIMEOUT => 10,
//                    ],
//                ],
//                'slaves' => [
//                    ['dsn' => 'mysql:host=127.0.0.1;dbname=payment_com'],
//                ],
            ],
            'redis' => [
                'class' => 'yii\redis\Connection',
                'hostname' => '127.0.0.1',
                'port' => 63780,
                'database' => 0,
            ],
        ],
        'params' => [
            'domain.cdn' => 'portal-api.gd95516.com',
            'domain.gateway' => 'gateway.gd95516.com',
            'domain.gateway.rpc' => 'gateway.gd95516.com',
            'corsOriginDomain' => ['*','portal-api.gd95516.com'],
        ],
    ]
);

return $config;