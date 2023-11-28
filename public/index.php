<?php
/* @var $app \Nepf2\Application */
$app = require_once __DIR__ . '/../app/bootstrap.php';


if ($app->cfg('site.maintenance')) {
    $app->router->addControllers([
        App\Controller\StaticMaintenance::class,
    ]);
} else {
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
    ]);

    $app->tasks->run(100);
}
$app->run();