<?php
!defined('TEST') && define('TEST', 'TEST');

require(__DIR__ . '/../../protected/config/mode.php');
require(__DIR__ . '/../../vendor/autoload.php');

$moduleName = getenv('MODULE_NAME');

\power\yii2\log\LogHelper::init();

$config = require sprintf('%s/../../protected/config/%s/test.php', __DIR__ , strtolower(APPLICATION_ENV));
new power\yii2\console\Application($config);

if (!empty($moduleName)) {
    // module custom config
    $moduleId = strtolower(preg_replace('#(?<!/)([A-Z])#', '-$1' , $moduleName));
    \Yii::$app->getModule($moduleId);
}
