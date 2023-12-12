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
use App\Model\Users;
use Nepf2\Application;

class TrashExpire
{
    public const DISPATCH_TIME = 6 * 3600;
    public const USER_TIME_MIN = 1 * 60;
    public const USER_TIME_MAX = 3 * 3600;

    public function __construct(
        protected Application $app
    )
    {
    }

    public function dispatch()
    {
        foreach (Users::findAll(['select' => ['id']]) as $user) {
            $when = random_int(self::USER_TIME_MIN, self::USER_TIME_MAX);
            $this->app->tasks->scheduleOnce([self::class, 'processUser'], [$user->id], $when);
        }
    }

    public function processUser(int $userId)
    {
        // Load the scheduled task's Identity
        $user = Users::findOne(['id' => $userId]);
        if ($user->id !== $userId)
            return;
        $identity = Identity::User($user);
        $context = new Context($this->app, $identity);

        // go through all deleted files owned by that user that have expired
        $deletedBefore = time() - $this->app->cfg('files.trash_days') * 24 * 3600;
        $deleted = Inodes::findBy(['owner_id' => $userId, 'deleted<' => $deletedBefore])->toArray(['column' => 'id']);

        if (!count($deleted))
            return;

        // something has expired, remove it
        $context->setupStorage();
        foreach ($deleted as $inodeId) {
            // inode could have been deleted in case parent and child expire at the same time, so we re-fetch each one
            if (is_null($inode = Inodes::Find($inodeId)))
                continue;
            $node = Node::FromInode($inode, $context);
            $node->removeRecursive();
        }
    }
}