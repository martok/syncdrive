<?php
/**
 * SyncDrive - main entry point
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

/* @var $app \Nepf2\Application */
$app = require_once __DIR__ . '/../app/bootstrap.php';


if ($app->cfg('site.maintenance')) {
    $app->router->addControllers([
        App\Controller\StaticMaintenance::class,
    ]);
} else {
    app_php_config($app);

    $app->router->addControllers([
        App\Controller\IndexController::class,
        App\Controller\UserController::class,
        App\Controller\FrontendAPIController::class,
        App\Controller\DavController::class,
        App\Controller\BrowseController::class,
        App\Controller\SharesController::class,
        App\Controller\TrashController::class,
        App\Controller\NextcloudController::class,
        App\Controller\ThumbnailController::class,
        App\Controller\AdminController::class,
    ]);

    if ($app->cfg('tasks.runMode') === 'request') {
        app_run_tasks($app);
    }
}
$app->run();