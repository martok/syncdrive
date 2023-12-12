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
use App\Dav\FS\Node;
use App\Dav\Identity;
use App\Model\Inodes;
use Nepf2\Application;

class CleanupOrphaned
{
    public const DISPATCH_TIME = 24 * 3600;

    public function __construct(
        protected Application $app
    )
    {
    }

    public function processFiles()
    {
        // This doesn't have to be super efficient, usually there should not be any matches
        $q = Inodes::db()->createSql();
        // order by user to get each affected user in order ASAP
        $q->select(['inode' => 'a.id'])
            ->from(['a' => Inodes::table()])
            ->leftJoin(['b' => Inodes::table()], ['b.id' => 'a.parent_id'])
            ->where('a.parent_id IS NOT NULL')
            ->andWhere('b.id IS NULL')
            ->orderBy('a.owner_id');
        $orphans = Inodes::execute($q);

        if (!$orphans->count())
            return;

        // setup context using dummy identity
        $context = new Context($this->app, Identity::System());
        $context->setupStorage();
        // inode could have been deleted in case parent and child expire at the same time, so we re-fetch each one
        $deleted = $orphans->toArray(['column' => 'inode']);
        foreach ($deleted as $inodeId) {
            if (!($inode = Inodes::Find($inodeId)))
                continue;
            $node = Node::FromInode($inode, $context);
            $node->removeRecursive(false);
        }
    }
}