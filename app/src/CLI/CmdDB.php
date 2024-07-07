<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\CLI;

use App\Manager;
use GetOpt\Command;

class CmdDB extends BaseCommand
{
    /**
     * @inheritDoc
     */
    public function getCommands(): array
    {
        return [
            Command::create('db:migrate', $this->migrate(...))
                ->setDescription('Database migrations'),
        ];
    }

    public function migrate(array $opts, array $operands): int
    {
        switch (Manager::RunMigrations($this->app)) {
            case Manager::MIGRATION_NOT_REQUIRED:
                echo "No migration required.";
                return 0;
            case Manager::MIGRATION_SUCCESS:
                echo "Migration successful.";
                return 0;
            case Manager::MIGRATION_FAIL_LOCKED:
                echo "Database upgrade in progress.";
                return 1;
            default:
                echo "Unknown migration result.";
                return 2;
        }
    }
}