<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\CLI;

use App\Dav\Context;
use App\Dav\Identity;
use App\ObjectStorage\ObjectStorage;
use GetOpt\Command;
use GetOpt\Operand;
use GetOpt\Option;
use Nepf2\Template\Template;

class CmdStorage extends BaseCommand
{
    /**
     * @inheritDoc
     */
    public function getCommands(): array
    {
        return [
            Command::create('storage:list', $this->list(...))
                ->addOption(Option::create('s', 'size')->setDescription('Calculate space usage'))
                ->setDescription('List Storage backends'),
            Command::create('storage:migrate', $this->migrate(...))
                ->addOption(Option::create('n', 'dry-run')->setDescription('Do not actually perform transfers'))
                ->addOption(Option::create('f', 'force')->setDescription('Do not verify mismatched storage intents'))
                ->addOption(Option::create('k', 'keep')->setDescription('Copy objects to DEST, don\'t remove from SOURCE'))
                ->addOperand(Operand::create('SOURCE', Operand::REQUIRED)->setDescription('ID of source backend'))
                ->addOperand(Operand::create('DEST', Operand::REQUIRED)->setDescription('ID of destination backend'))
                ->setDescription('Migrate data from one storage backend to another'),
        ];
    }

    private function getStorage(): ObjectStorage
    {
        $identity = Identity::System();
        $context = new Context($this->app, $identity);
        $context->setupStorage();

        return $context->storage;
    }

    public function list(array $opts, array $operands): int
    {
        $storage = $this->getStorage();
        $withSize = isset($opts['size']);
        foreach ($storage->getBackends() as $idx => $bd) {
            echo sprintf("%3d: %s\n", $idx, get_class($bd->backend));
            echo sprintf("     Intent: %s\n", $bd->intentToStr());
            if ($withSize) {
                $used = -1;
                $avail = -1;
                if ($bd->backend->estimateCapacity($used, $avail)) {
                    echo sprintf("     Usage: %s\n", $used < 0 ? '?' : Template::formatFileSize($used));
                    echo sprintf("     Available: %s\n", $avail < 0 ? '?' : Template::formatFileSize($avail));
                } else {
                    echo "     Usage unavailable\n";
                }
            }
        }
        return 0;
    }

    public function migrate(array $opts, array $operands): int
    {
        $dryrun = isset($opts['dry-run']);
        $ignoreChecks = isset($opts['force']);
        $keep = isset($opts['keep']);

        $srcid = $operands[0];
        $destid = $operands[1];
        if (!is_numeric($srcid) || !is_numeric($destid)) {
            fputs(STDERR, "Source and Dest must be numeric\n");
            return 1;
        }
        $srcid = (int)$srcid;
        $destid = (int)$destid;

        $storage = $this->getStorage();
        $backends = $storage->getBackends();

        if (0 > $srcid || count($backends) <= $srcid || 0 > $destid || count($backends) <= $destid) {
            fputs(STDERR, "Source and Dest must be valid backend indices, see storage:list\n");
            return 1;
        }
        if ($srcid === $destid) {
            fputs(STDERR, "Source and Dest must not be the same\n");
            return 1;
        }
        $srcDef = $backends[$srcid];
        $destDef = $backends[$destid];

        echo "Transfer files from:\n";
        echo sprintf("%3d: %s, %s\n", $srcid, get_class($srcDef->backend), $srcDef->intentToStr());
        echo "To:\n";
        echo sprintf("%3d: %s, %s\n", $destid, get_class($destDef->backend), $srcDef->intentToStr());

        if ($srcDef->intent !== $destDef->intent && !$ignoreChecks) {
            fputs(STDERR, "Source and Dest have different intents, pass -f to ignore\n");
            return 1;
        }

        $source = $srcDef->backend;
        $destination = $destDef->backend;

        if ($dryrun) {
            echo "Showing plan of copy operation...\n";
        } else {
            echo "Beginning copy operation...\n";
        }

        $totalCount = 0;
        $totalSize = 0;
        $startTime = time();
        foreach ($source->storedObjectsIterator() as $obj) {
            echo "   {$obj->object}...";
            if ($dryrun || $storage->objectCopy($obj->object, $source, $obj->object, $destination, !$keep)) {
                $status = $keep ? 'copied' : 'moved';
                echo " {$status}.\n";
                $totalCount += 1;
                $totalSize += $obj->size;
            } else {
                echo " failed.\n";
                echo "An Error has occurred, aborting process.\n";
                return 2;
            }
        }
        $dTime = time() - $startTime;
        echo sprintf("Finished for %d objects, total %s\n", $totalCount, Template::formatFileSize($totalSize));
        echo sprintf("Average speed: %s/ s\n", Template::formatFileSize($totalSize / $dTime));

        return 0;
    }
}