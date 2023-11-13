<?php

namespace App\Dav;

use App\Model\InodeLocks;
use Sabre\DAV\Locks\Backend\AbstractBackend;
use Sabre\DAV\Locks\LockInfo;
use Sabre\DAV\Tree;

class LocksBackend extends AbstractBackend
{
    private readonly Tree $tree;
    private bool $locksExpired = false;

    public function __construct(Tree $tree)
    {
        $this->tree = $tree;
    }

    protected function expireLocksOnce(): void
    {
        if ($this->locksExpired)
            return;
        $sql = InodeLocks::db()->createSql();
        $sql->delete()
            ->from(InodeLocks::table())
            ->where('expires <= :now');
        InodeLocks::execute($sql, ['now' => time()]);
        $this->locksExpired = true;
    }

    /**
     * @inheritDoc
     */
    public function getLocks($uri, $returnChildLocks): array
    {
        $this->expireLocksOnce();

        $pathNodes = NodeResolver::TreeGetNodesOfPath($this->tree, $uri);
        // convert to numeric. if all were found, the last one corresponds to $uri.
        $inodes = NodeResolver::NodesToInodes($pathNodes);
        $matchedInode = count($inodes) == count($pathNodes) ? $inodes[count($inodes) - 1] : -1;

        $foundLocks = [];
        // check if any of the parents is locked with a deep lock, or the matched node itself
        foreach (InodeLocks::findBy(['inode_id' => $inodes]) as $lock) {
            if ($lock->depth !== 0 || $matchedInode == $lock->inode_id)
                $foundLocks[$lock->id] = $lock;
        }

        // return children only if we even found the requested item and it is a collection
        $considerChildren = $returnChildLocks &&
                            ($matchedInode >= 0) &&
                            ($pathNodes[count($pathNodes)-1] instanceof Directory);
        if ($considerChildren) {
            // assuming there are only very few concurrently active locks and possible very many files, it is faster
            // to check each lock we don't already know about if it is related to the currently handled subtree.
            // However, "subtree" includes shared folders, as a file in a shared folder must be found by any
            // location for any user having access to that shared folder. This makes this check very expensive.
            foreach (InodeLocks::findBy(['inode_id-' => $inodes]) as $lock) {
                if (!isset($foundLocks[$lock->id]) &&
                    NodeResolver::InodeVisibleIn($lock->inode_id, $matchedInode)) {
                    $foundLocks[] = $lock;
                }
            }
        }

        return array_map(function ($lock) {
                            $lockInfo = new LockInfo();
                            $lockInfo->token = $lock->token;
                            $lockInfo->owner = $lock->owner;
                            $lockInfo->timeout = $lock->expires - $lock->created;
                            $lockInfo->scope = $lock->scope;
                            $lockInfo->depth = $lock->depth;
                            $lockInfo->created = $lock->created;
                            $lockInfo->uri = (string)$lock->inode_id;
                            return $lockInfo;
                        }, array_values($foundLocks));
    }

    /**
     * @inheritDoc
     */
    public function lock($uri, LockInfo $lockInfo): bool
    {
        $this->expireLocksOnce();
        $lockInfo->created = time();
        $lockInfo->uri = $uri;

        $node = $this->tree->getNodeForPath($uri);
        $lockData = [
            'owner' => $lockInfo->owner,
            'scope' => $lockInfo->scope,
            'depth' => $lockInfo->depth,
            'created' => $lockInfo->created,
            'expires' => $lockInfo->created + $lockInfo->timeout,
        ];

        $lock = InodeLocks::findOne(['token' => $lockInfo->token]);
        if (!isset($lock->id)) {
            $lockData['inode_id'] = $node->getInodeId();
            $lockData['token'] = $lockInfo->token;
            $lock = new InodeLocks($lockData);
        } else {
            foreach($lockData as $k => $v)
                $lock->$k = $v;
        }
        $lock->save();
        return true;
    }

    /**
     * @inheritDoc
     */
    public function unlock($uri, LockInfo $lockInfo): bool
    {
        $this->expireLocksOnce();
        $node = $this->tree->getNodeForPath($uri);
        $lock = InodeLocks::findOne(['inode_id' => $node->getInodeId(), 'token' => $lockInfo->token]);
        if (isset($lock->id)) {
            $lock->delete();
            return true;
        }
        return false;
    }
}