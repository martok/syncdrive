<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App;

use Nepf2\Application;

class Manager
{
    private const FILE_MIGRATION_LOCK = 'data/db_migration.lock';

    public static function InMaintenanceMode(Application $app): bool
    {
        return $app->cfg('site.maintenance');
    }

    public const MIGRATION_NOT_REQUIRED = 0;
    public const MIGRATION_SUCCESS = 1;
    public const MIGRATION_FAIL_LOCKED = -1;

    public static function RunMigrations(Application $app): int
    {
        /** @var \Nepf2\Database\Migrator $migrator */
        $migrator = $app->db->migrator();

        if (0 == count($migrator->pendingMigrations())) {
            // nothing to do
            return self::MIGRATION_NOT_REQUIRED;
        }

        $lockfile = $app->expandPath(self::FILE_MIGRATION_LOCK);
        $lock = fopen($lockfile, 'w+');
        try {
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB, $blocked)) {
                // file error or another process already has the lock, don't continue
                return self::MIGRATION_FAIL_LOCKED;
            }
            // holding the lock, run migrations
            $migrator->runAll();
            return self::MIGRATION_SUCCESS;
        } finally {
            // also releases held locks
            fclose($lock);
        }
    }
}