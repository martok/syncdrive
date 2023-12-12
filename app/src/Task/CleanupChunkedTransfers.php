<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Task;

use App\Dav\Context;
use App\Dav\Identity;
use App\Model\ChunkedUploadParts;
use App\Model\ChunkedUploads;
use Nepf2\Application;

class CleanupChunkedTransfers
{
    public const DISPATCH_TIME = 24 * 3600;
    public const MIN_AGE = 6 * 3600;

    public function __construct(
        protected Application $app
    )
    {
    }

    public function process()
    {
        // first, remove any transfer that is older than the cutoff time
        $startedBefore = time() - self::MIN_AGE;
        $q = ChunkedUploads::db()->createSql();
        $q->delete(ChunkedUploads::table())
            ->where('started < :limit');
        ChunkedUploads::execute($q, ['limit' => $startedBefore]);

        $q->reset();
        // now, find parts that don't point to valid transfers (anymore)
        $q->select(['p.id', 'p.object'])
            ->from(['p' => ChunkedUploadParts::table()])
            ->leftJoin(['u' => ChunkedUploads::table()], ['u.id' => 'p.upload_id'])
            ->where('u.id IS NULL')
            ->orderBy('p.upload_id');
        $orphans = ChunkedUploadParts::execute($q);

        if (!$orphans->count())
            return;

        // setup context using dummy identity
        $context = new Context($this->app, Identity::System());
        $context->setupStorage();
        foreach ($orphans as $orphan) {
            $context->storage->safeRemoveObject($orphan->object);
            $orphan->delete();
        }
    }
}