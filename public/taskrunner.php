<?php
/* @var $app \Nepf2\Application */
$app = require_once __DIR__ . '/../app/bootstrap.php';


if (!$app->cfg('site.maintenance') &&
    in_array($app->cfg('tasks.runMode'), ['cron', 'webcron'])) {
    $token = $app->cfg('tasks.webtoken');
    if ($app->isCLI || empty($token) || ($_GET['token']??'') === $token) {
        app_run_tasks($app);
    }
}
