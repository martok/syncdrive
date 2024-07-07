<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\CLI;

use GetOpt\Command;
use Nepf2\Application;

abstract class BaseCommand
{
    /**
     * @inheritDoc
     */
    public function __construct(
        protected Application $app
    )
    {
    }


    /**
     *
     * @return Command[]
     */
    abstract public function getCommands(): array;
}