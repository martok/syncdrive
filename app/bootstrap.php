<?php
/**
 * SyncDrive - shared bootstrap
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

define('REPO_ROOT', realpath(__DIR__ . '/../'));

$autoloader = require_once REPO_ROOT . '/vendor/autoload.php';


function app_run_tasks(\Nepf2\Application $app): void
{
    // ensure the core tasks are always present
    $app->tasks->scheduleOnce([\App\Task\TrashExpire::class, 'dispatch'], [], \App\Task\TrashExpire::DISPATCH_TIME);
    $app->tasks->scheduleOnce([\App\Task\VersionsExpire::class, 'dispatch'], [], \App\Task\VersionsExpire::DISPATCH_TIME);
    $app->tasks->scheduleOnce([\App\Task\CleanupOrphaned::class, 'processFiles'], [], \App\Task\CleanupOrphaned::DISPATCH_TIME);
    $app->tasks->scheduleOnce([\App\Task\CleanupChunkedTransfers::class, 'process'], [], \App\Task\CleanupChunkedTransfers::DISPATCH_TIME);

    $app->tasks->run($app->cfg('tasks.maxRunTime'));
}

$app = new Nepf2\Application();
$app->setRoot(REPO_ROOT);
$cfg = $app->mergeConfigs([
	'app/config.base.php',
	'data/config.user.php',
]);
$app->setUserConfig($cfg);
$app->setLogConfig($cfg['log']);

$app->addComponent(\Nepf2\Database\Database::class, config: [
    'migrations_path' => 'app/database',
    'migrations_state' => 'data/db_migration.current',
] + $cfg['db']);
$app->addComponent(\Nepf2\Router\Router::class);
$app->addComponent(\Nepf2\Session\Session::class, config: [
    'lifetime' => 3600 * 6,
]);
$app->addComponent(\Nepf2\TaskScheduler\TaskScheduler::class);
$app->addComponent(\Nepf2\Template\Template::class, config: [
	'templates' => 'app/view',
]);

/** @var \Nepf2\Database\Migrator $migrator */
$migrator = $app->db->migrator();
$migrator->runAll();

return $app;