<?php
/**
 * SyncDrive - task runner entry point
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

/* @var $app \Nepf2\Application */
$app = require_once __DIR__ . '/../app/bootstrap.php';


if (!$app->cfg('site.maintenance') &&
    in_array($app->cfg('tasks.runMode'), ['cron', 'webcron'])) {
    $token = $app->cfg('tasks.webtoken');
    if ($app->isCLI || empty($token) || ($_GET['token']??'') === $token) {
        app_run_tasks($app);
    }
}
