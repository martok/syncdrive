<?php

namespace App\Task;

use App\Dav\Context;
use App\Dav\FS\File;
use App\Dav\FS\Node;
use App\Dav\Identity;
use App\Model\FileVersions;
use App\Model\Inodes;
use App\Model\Users;
use Nepf2\Application;

class VersionsExpire
{
    public const DISPATCH_TIME = 6 * 3600;
    public const USER_TIME_MIN = 1 * 60;
    public const USER_TIME_MAX = 3 * 3600;
    public const CHUNK_TIME_MIN = 5;
    public const CHUNK_TIME_MAX = 5 * 60;
    public const CHUNK_SIZE = 1000;

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

        // go through all files owned by that user ...
        $files = Inodes::findBy(['owner_id' => $userId, 'type' => Inodes::TYPE_FILE], ['order' => 'id ASC']);

        // ... and schedule checking their versions
        for ($start = 0; $start < count($files); $start += self::CHUNK_SIZE) {
            $end = min(count($files) - 1, $start + self::CHUNK_SIZE);
            $first = $files[$start]->id;
            $last = $files[$end]->id;
            $when = random_int(self::CHUNK_TIME_MIN, self::CHUNK_TIME_MAX);
            $this->app->tasks->scheduleOnce([self::class, 'processFiles'], [$userId, $first, $last], $when);
        }
    }

    public function processFiles(int $userId, int $firstInode, int $lastInode)
    {
        // Load the scheduled task's Identity
        $user = Users::findOne(['id' => $userId]);
        if ($user->id !== $userId)
            return;
        $identity = Identity::User($user);
        $context = new Context($this->app, $identity);

        // go through the subset of files
        $files = Inodes::findBy(['owner_id' => $userId, 'type' => Inodes::TYPE_FILE,
                                 'id>=' => $firstInode, 'id<=' => $lastInode]);

        $context->setupStorage();
        foreach ($files as $inode) {
            $node = Node::FromInode($inode, $context);
            if (!$node instanceof File)
                continue;
            $candidates = $this->autoExpireList($node->getVersions(), $node->getCurrentVersion());
            foreach ($candidates as $version) {
                $node->removeVersion($version);
            }
        }
    }

    protected function autoExpireList(array $versions, FileVersions $current): array
    {
        $config = $this->app->cfg('files.versions');
        $maxAge = time() - $config['max_days'] * 24 * 3600;
        $expired = [];

        $interval = 0;
        $intervalEnd = time() - $config['intervals'][$interval][0];
        $intervalKeepEvery = $config['intervals'][$interval][1];
        $prevKeep = null;
        $prevSeen = null;

        $keepFileVersionTest = function($version)
            use ($current, &$prevSeen, &$prevKeep,
                 $config, $maxAge, &$interval, &$intervalEnd, &$intervalKeepEvery): bool {
            // always keep the newest, current and any named version
            if (is_null($prevKeep) || !is_null($version->name) || $version->id == $current->id) {
                return true;
            }

            // zero-byte files a short time before a non-zero revision are always cleaned up,
            // regardless of interval rules (for example, Windows DAV client does 0-byte PUTs first)
            if (($version->size == 0) &&
                !is_null($prevSeen) && ($prevSeen->created - $version->created <= $config['zero_byte_seconds'])) {
                return false;
            }

            // older than the maximum limit - always expire
            if ($version->created < $maxAge) {
                return false;
            }

            // is this the same interval as the previously kept one?
            if ($version->created <= $intervalEnd) {
                // new interval begins, so we keep this version
                $interval++;
                if ($config['intervals'][$interval][0] > 0)
                    $intervalEnd -= $config['intervals'][$interval][0];
                else
                    $intervalEnd = 0;
                $intervalKeepEvery = $config['intervals'][$interval][1];
                return true;
            }

            // file is in the same interval as the previous one
            if ($version->created < $prevKeep->created - $intervalKeepEvery) {
                // enough difference, keep it
                return true;
            }

            // no rule wants to keep this version, expire it
            return false;
        };

        foreach($versions as $version) {
            if ($keepFileVersionTest($version)) {
                $prevKeep = $version;
            } else {
                $expired[] = $version;
            }
            $prevSeen = $version;
        }

        return $expired;
    }
}