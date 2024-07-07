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

class CmdCfg extends BaseCommand
{
    /**
     * @inheritDoc
     */
    public function getCommands(): array
    {
        return [
            Command::create('cfg:print', $this->print(...))
                ->setDescription('Show current config'),
        ];
    }

    public function print(array $opts, array $operands): int
    {
        echo json_encode($this->app->cfg(), JSON_PRETTY_PRINT);
        return 0;
    }
}