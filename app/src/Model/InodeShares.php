<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Model;

use App\Dav\NodeResolver;
use Pop\Db\Record\Collection;

class InodeShares extends \Pop\Db\Record
{

    /*
     * int id
     * int inode_id Inode
     * int sharer_id User
     * int modified utctime
     * varchar permissions
     * varchar token
     * varchar(255) password
     */

    public static function EnumReceivedShares(int $userid): array
    {
        $result = [];
        foreach (Inodes::findBy(['type' => Inodes::TYPE_INTERNAL_SHARE,
                                 'owner_id' => $userid,
                                 'deleted' => null],
                                 ['select' => ['id', 'link_target', 'name']]) as $inode) {
            $share = InodeShares::findOne(['id' => $inode->link_target]);
            $result[] = [$share, $inode];
        }
        return $result;
    }

    public static function EnumUserShares(int $userid): array
    {
        $result = [];
        foreach (InodeShares::findBy(['sharer_id' => $userid]) as $share) {
            $inode = Inodes::Find($share->inode_id);
            if ($inode->owner_id !== $userid)
                // Re-shares are not shown here, instead, they are listed as received shares
                continue;
            $result[] = [$share, $inode];
        }
        return $result;
    }

    public static function EnumInodeShares(Inodes $inode, int $userid): array
    {
        $result = [];
        // is the Inode itself an incoming share?
        if ($inode->type === Inodes::TYPE_INTERNAL_SHARE) {
            $share = InodeShares::findOne(['id' => $inode->link_target]);
            if (!is_null($share->id))
                $result[$share->id] = $share;
        }
        // is the node shared as anything by this user?
        foreach (InodeShares::findBy(['sharer_id' => $userid, 'inode_id' => $inode->id]) as $share) {
            $result[$share->id] = $share;
        }
        return array_values($result);
    }

    public static function FindInodes(int $shareId): Collection
    {
        return Inodes::findby(['type' => Inodes::TYPE_INTERNAL_SHARE, 'link_target' => $shareId]);
    }

    public static function UsersWithAccess(int $shareid): array
    {
        $result = [];
        foreach (InodeShares::FindInodes($shareid) as $inode) {
            $user = Users::findOne(['id' => $inode->owner_id]);
            if (!is_null($user->id)) {
                $result[$user->id] = $user;
            }
        }
        return array_values($result);
    }

    public function createLinkInUser(Users $user): bool
    {
        if (!($target = Inodes::Find($this->inode_id)))
            return false;
        if (is_null($linkName = NodeResolver::InodeIncrementalName($user->root(), $target->name)))
            return false;
        // create an inode in that user's root, owned by that user, pointing to this share
        $inode = new Inodes([
            'parent_id' => $user->root()->id,
            'owner_id' => $user->id,
            'type' => Inodes::TYPE_INTERNAL_SHARE,
            'name' => $linkName,
            'modified' => time(),
            'link_target' => $this->id,
        ]);
        $inode->save();
        return true;
    }

    private function deleteLinkInode(Inodes $inode): void
    {
        // A link Inode cannot have children or versions, making this function simpler
        // than Node::removeRecursive
        $parent = $inode->parent_id;
        $inode->deleteWithRefs();
        Inodes::NodeContentChanged($parent);
    }

    public function removeLinksInUser(Users $user): void
    {
        // there should only be one
        foreach (Inodes::findby(['type' => Inodes::TYPE_INTERNAL_SHARE, 'link_target' => $this->id,
                                 'owner_id' => $user->id]) as $inode) {
            $this->deleteLinkInode($inode);
        }
    }

    public function removeShareAndLinks(): void
    {
        // remove all links to this share
        foreach (InodeShares::FindInodes($this->id) as $inode) {
            $this->deleteLinkInode($inode);
        }
        // and self
        $this->delete();
    }
}