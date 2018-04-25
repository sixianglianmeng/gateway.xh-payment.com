<?php
$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../base.php',
    [
        'params'    => [
        ]
    ]
);

return $config;
