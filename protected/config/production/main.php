<?php
$GLOBALS['db.mysql'] = [
];
$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../base.php',
    [
        'components' => [
            'db' => [
                'class' => 'yii\db\Connection',

                // 主库的配置
                'dsn' => 'mysql:host=rm-8vbsw047921fwb8e8.mysql.zhangbei.rds.aliyuncs.com;dbname=cloud_accessory',
                'username' => 'cloud_accessory',
                'password' => '33f-92-6efFc98a5e6',
                'charset' => 'utf8',
                'tablePrefix' => 'acc_',

                // 从库的通用配置
                'slaveConfig' => [
                    'username' => 'cloud_accessory',
                    'password' => '33f-92-6efFc98a5e6',
                    'attributes' => [
                        // 使用一个更小的连接超时
                        //PDO::ATTR_TIMEOUT => 10,
                    ],
                ],

                // 从库的配置列表
                'slaves' => [
                    ['dsn' => 'mysql:host=rm-8vbsw047921fwb8e8.mysql.zhangbei.rds.aliyuncs.com;dbname=cloud_accessory'],
                ],
            ],
            'db_sale' => [
                'class' => 'yii\db\Connection',
                'dsn' => 'mysql:host=rm-8vbsw047921fwb8e8.mysql.zhangbei.rds.aliyuncs.com;dbname=cloud_accessory',
                'username' => 'cloud_accessory',
                'password' => '33f-92-6efFc98a5e6',
                'charset' => 'utf8',
            ],
            'db_financial_report' => [
                'class' => 'yii\db\Connection',
                'dsn' => 'mysql:host=rm-8vbsw047921fwb8e8.mysql.zhangbei.rds.aliyuncs.com;dbname=financial_report',
                'username' => 'dts',
                'password' => '33f-92-6efFc98a5e6',
                'charset' => 'utf8',
                'tablePrefix' => 'acc_',
//            'enableLogging'=>true,
            ],
            'db_mall' => [
                'class' => 'yii\db\Connection',
                'dsn' => 'mysql:host=rm-8vbsw047921fwb8e8.mysql.zhangbei.rds.aliyuncs.com;dbname=mall_payment_com',
                'username' => 'dts',
                'password' => '33f-92-6efFc98a5e6',
                'charset' => 'utf8',
                'tablePrefix' => 'pigcms_',
//            'enableLogging'=>true,
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