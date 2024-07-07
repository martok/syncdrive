<?php
/**
 * SyncDrive - CLI Admin interface
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

use GetOpt\ArgumentException;
use GetOpt\ArgumentException\Missing;
use GetOpt\GetOpt;
use GetOpt\Option;

if (php_sapi_name() !== 'cli') {
    echo "This script can be run from the command line only.\n";
    exit(1);
}

// catch all unhandled exceptions for error logging
function cli_exception_handler($exception): never
{
    fputs(STDERR, "Unhandled exception:\n");
    fputs(STDERR, (string)$exception);
    exit(1);
}
set_exception_handler('cli_exception_handler');

// locate paths
define('REPO_ROOT', realpath(__DIR__ . '/../'));
$cliInitialWorkDir = getcwd();
if (false === $cliInitialWorkDir) {
    fputs(STDERR, "Failed to locate current directory. This may be a permission issue.\n");
    exit(1);
}

// setup autoloader
$autoloader = require_once REPO_ROOT . '/vendor/autoload.php';

cli_php_config();

$app = new \Nepf2\Application();
cli_app_config($app);
cli_main($app);

function cli_php_config(): void
{
    @ini_set('display_errors', 1);
    if (!str_contains(@ini_get('disable_functions'), 'set_time_limit')) {
        @set_time_limit(0);
    }
    if (ini_parse_quantity(ini_get('memory_limit')) < ini_parse_quantity('512M'))
        ini_set('memory_limit', '512M');
}

function cli_app_config(\Nepf2\Application $app): void
{
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
}

function cli_main(\Nepf2\Application $app): void
{
    $go = new GetOpt();
    $go->addOption(Option::create('?', 'help', GetOpt::NO_ARGUMENT)
                         ->setDescription('Show help and quit'));

    // register subcommand handlers
    $commandHandlers = [
        App\CLI\CmdCfg::class,
        App\CLI\CmdDB::class,
    ];
    /** @var class-string<App\CLI\BaseCommand> $commandClass */
    foreach ($commandHandlers as $commandClass) {
        $inst = new $commandClass($app);
        $go->addCommands($inst->getCommands());
    }

    // parse arguments
    try {
        try {
            $go->process();
        } catch (Missing $exception) {
            // only raise missing arg errors if help was not requested anyway
            if (!$go->getOption('help')) {
                throw $exception;
            }
        }
    } catch (ArgumentException $exception) {
        fputs(STDERR, $exception->getMessage() . PHP_EOL);
        fputs(STDERR, PHP_EOL . $go->getHelpText());
        exit(1);
    }

    // show help and quit
    $command = $go->getCommand();
    if (!$command || $go->getOption('help')) {
        echo $go->getHelpText();
        exit(0);
    }

    // call the requested command
    $retval = call_user_func($command->getHandler(), $go->getOptions(), $go->getOperands());
    exit($retval);
}
