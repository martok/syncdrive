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

function app_php_config(\Nepf2\Application $app): void
{
    @ini_set('default_charset', 'UTF-8');

    // if we're not showing errors, ensure they are logged somewhere
    if (!ini_get('display_errors'))
        @ini_set('log_errors', 1);

    // TODO: should the defaults be configurable?
    if (ini_parse_quantity(ini_get('memory_limit')) < ini_parse_quantity('512M'))
        ini_set('memory_limit', '512M');
    if (intval(ini_get('max_execution_time')) < 900)
        ini_set('max_execution_time', 900);

    // make sure the changed config gets applied
    if (!str_contains(@ini_get('disable_functions'), 'set_time_limit')) {
        @set_time_limit(max(intval(@ini_get('max_execution_time')), intval(@ini_get('max_input_time'))));
    }
}

function app_run_migrations(\Nepf2\Application $app): void
{
    switch (\App\Manager::RunMigrations($app)) {
        case \App\Manager::MIGRATION_NOT_REQUIRED:
        case \App\Manager::MIGRATION_SUCCESS:
            break;
        case \App\Manager::MIGRATION_FAIL_LOCKED:
            die('Database upgrade in progress');
    }
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

app_run_migrations($app);

return $app;