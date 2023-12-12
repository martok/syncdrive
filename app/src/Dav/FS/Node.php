<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav\FS;

use App\Dav\Context;
use App\Dav\IACLTarget;
use App\Dav\IIndexableCollection;
use App\Dav\Perm;
use App\Dav\PermSet;
use App\Model;
use App\Model\InodeShares;
use Sabre\DAV\Exception;
use Sabre\DAV\INode;

class Node implements INode, IACLTarget
{
    protected Model\Inodes $inode;
    protected ?Model\Inodes $targetInode = null;
    public readonly Context $ctx;
    protected ?PermSet $targetPermissions = null;
    private PermSet $effectivePermissions;

    private static function CreateNodeByType(int $type, Model\Inodes $inode, Context $context): self
    {
        switch ($type) {
            case $inode::TYPE_COLLECTION:
                return new Directory($inode, $context);
            case $inode::TYPE_FILE:
                return new File($inode, $context);
            default:
                throw new Exception\BadRequest("Unknown inode type: {$inode->type}");
        }
    }

    public static function FromInode(Model\Inodes $inode, Context $context): self
    {
        $linkInfo = $inode->getLinkInfo();
        if (!is_null($linkInfo)) {
            $linkTarget = $linkInfo['target'];
            // avoid recursion chains: the target of any link must be a regular file or folder
            if (!Model\Inodes::IsRegularNodeType($linkTarget->type)) {
                throw new Exception\BadRequest('Share target is not regular file/directory');
            }
            $node = self::CreateNodeByType($linkTarget->type, $inode, $context);
            $node->setTargetInode($linkTarget, new PermSet($linkInfo['permissions']));
            return $node;
        } else {
            return self::CreateNodeByType($inode->type, $inode, $context);
        }
    }

    public static function ValidateFileName(string $name): bool
    {
        if ($name == '.' || $name == '..')
            return false;
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, ':'))
            return false;
        return true;
    }

    public static function SplitQualifiedName(string $urlpart, ?string &$name, ?int &$inode): bool
    {
        $parts = explode(':', $urlpart);
        if (count($parts) == 2) {
            $name = $parts[1];
            $inode = (int)$parts[0];
            return true;
        } elseif (count($parts) == 1) {
            $name = $parts[0];
            $inode = null;
            return true;
        }
        return false;
    }

    public function __construct(int|Model\Inodes $inode, Context $context)
    {
        if (is_int($inode))
            $inode = Model\Inodes::Find($inode);
        $this->inode = $inode;
        $this->ctx = $context;
        $this->effectivePermissions = new PermSet(Perm::DEFAULT_OWNED);
    }

    public function __toString(): string
    {
        $s = $this->getQualifiedName();
        if ($this->isLink())
            $s.= '->' . $this->getInode(true)->name;
        return $s;
    }

    protected function setTargetInode(Model\Inodes $target, PermSet $targetPermissions): void
    {
        $this->targetInode = $target;
        // the information from the link target is split:
        //   some refers to things that can be done with this node, and some are only relevant for getInnerPerms.
        $this->targetPermissions = $targetPermissions->without(Perm::LINK_OUTER_MASK);
        $this->effectivePermissions = $this->effectivePermissions->with(Perm::IS_SHARED | $targetPermissions->without(~Perm::LINK_OUTER_MASK)->value());
    }

    /**
     * Get the Inode associated with this Node.
     * Most users will want to follow links, which is what that function does by default. If the
     * actual node is wanted, pass false.
     *
     * @param bool $followLinks Default: true, follow any Links
     * @return int
     */
    public function getInode(bool $followLinks=true): Model\Inodes
    {
        return $followLinks && !is_null($this->targetInode) ? $this->targetInode
                                                            : $this->inode;
    }

    /**
     * Get the Id of the Inode associated with this Node.
     *
     * @param bool $followLinks Default: true, follow any Links
     * @return int
     */
    public function getInodeId(bool $followLinks=true): int
    {
        return $this->getInode($followLinks)->id;
    }

    public function isLink(): bool
    {
        return !is_null($this->targetInode);
    }

    public function hasShares(): bool
    {
        return !!InodeShares::getTotal(['inode_id' => $this->getInodeId(false)]);
    }

    protected function newItemOwner(bool $forVersion): int
    {
        if ($forVersion) {
            // versions track who actually made a change - the current user if logged in, otherwise use file owner
            return $this->ctx->identity->getUserId() ?? $this->getInode(true)->owner_id;
        } else {
            // the inodes themselves inherit ownership from the tree, so even files created in shared folders remain tree-owned
            return $this->getInode(true)->owner_id;
        }
    }

    public function deletedTimestamp(): ?int
    {
        return $this->inode->deleted;
    }

    public function isDeleted(): bool
    {
        return !is_null($this->inode->deleted);
    }

    /**
     * @inheritDoc
     */
    public function delete()
    {
        $this->requirePerm(Perm::CAN_DELETE);
        Model\Inodes::db()->beginTransaction();
        $this->inode->setDeleted(true);
        Model\Inodes::NodeContentChanged($this->inode->parent_id);
        Model\Inodes::db()->commit();
    }

    public function restore(): bool
    {
        if (Model\Inodes::getTotal(['name' => $this->inode->name,
                                    'parent_id' => $this->inode->parent_id,
                                    'deleted' => null]) != 0) {
            // name is in use, can't restore
            return false;
        }

        Model\Inodes::db()->beginTransaction();
        $this->inode->setDeleted(false);
        Model\Inodes::NodeContentChanged($this->inode->parent_id);
        Model\Inodes::db()->commit();
        return true;
    }

    protected function internalRemove(): void
    {
        $this->inode->deleteWithRefs();
    }

    /**
     * @param bool $updateParent should the parent node be updated
     * @return int[] Inode IDs that were deleted
     */
    public function removeRecursive(bool $updateParent = true): array
    {
        /*
         * Removing each individual Inode needs to be transacted (at least as far as the ObjectStore allows),
         * removing the contents of a Collection doesn't need to be. Once the parent node is gone, the children
         * could be garbage-collected later and are otherwise consistent.
         */

        $deletedInodes = [];
        $parentToUpdate = $updateParent ? $this->inode->parent_id : null;
        $nodeToDelete = $this->inode->id;

        $children = $this instanceof IIndexableCollection ? $this->getChildrenWithDeleted() : [];

        Model\Inodes::db()->beginTransaction();
        // delete any share referring to this inode, and all references to these shares
        foreach (Model\InodeShares::findBy(['inode_id' => $this->inode->id]) as $share) {
            $share->removeShareAndLinks();
        }
        // delete the Inode + storage
        $this->internalRemove();
        // update the parent (the parent could have been deleted already)
        Model\Inodes::NodeContentChanged($parentToUpdate);
        Model\Inodes::db()->commit();
        $deletedInodes[] = $nodeToDelete;

        // the node is gone now, clean up orphaned files
        foreach ($children as $childNode) {
            array_push($deletedInodes, ...$childNode->removeRecursive(false));
        }

        return $deletedInodes;
    }

    protected function copyTo(Directory $parent, ?string $newName): void
    {
        throw new \BadMethodCallException("Not implemented");
    }

    public function getQualifiedName(): string
    {
        return $this->getInode()->getQualifiedName();
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return $this->inode->name;
    }

    /**
     * @inheritDoc
     */
    public function setName($name)
    {
        if (!$this->inode->parent_id) {
            throw new Exception\Forbidden('Cannot rename the root node');
        }
        $this->requirePerm(Perm::CAN_RENAME);
        if (!$this->ValidateFileName($name)) {
            throw new Exception\Forbidden('Invalid file name');
        }
        $parentInode = Model\Inodes::Find($this->inode->parent_id);
        $parent = Node::FromInode($parentInode, $this->ctx);
        if ($parent->childExists($name)) {
            throw new Exception\Conflict('Target already exists');
        }
        $this->inode->name = $name;
        $this->inode->save();
        // changing the children changes the etag
        $parentInode->contentChanged(save: true);
    }

    /**
     * @inheritDoc
     */
    public function getLastModified()
    {
        return (int)($this->getInode()->modified);
    }

    /**
     * Implement {@see IFile::getEtag} here since the OC client needs it everywhere.
     */
    public function getETag()
    {
        return sprintf('"%s"', $this->getInode()->etag);
    }

    /**
     * Implement {@see IFile::getSize} here since the OC client needs it everywhere.
     */
    public function getSize()
    {
        return $this->getInode()->size;
    }

    public function getFileID(): string
    {
        return $this->getInodeId();
    }

    public function isOwned(bool $followLinks = true): bool
    {
        return $this->ctx->identity->getUserId() === $this->getInode($followLinks)->owner_id;
    }

    public function getPerms(): PermSet
    {
        return $this->ctx->filterPermissions($this->effectivePermissions);
    }

    public function getInnerPerms(): PermSet
    {
        $innerPerms = $this->getPerms()->without(Perm::FLAG_MASK);
        // share permissions are "between" the outside (link) and inside (shared folder/file)
        if (!is_null($this->targetPermissions) && !$this->isOwned())
            $innerPerms = $this->targetPermissions->inherit($innerPerms->value());
        return $innerPerms;
    }

    public function inheritPerms(PermSet $declared): void
    {
        $inheritSet = $declared->value();
        if ($this->isOwned(false))
            // never restrict owned files beyond default set
            $inheritSet |= Perm::DEFAULT_OWNED;
        $this->effectivePermissions = $this->effectivePermissions->inherit($inheritSet);
    }

    public function requirePerm(int $permissions): void
    {
        if (!$this->getPerms()->has($permissions))
            throw new Exception\Forbidden('Not allowed');
    }

    public function requireInnerPerm(int $permissions): void
    {
        if (!$this->getInnerPerms()->has($permissions))
            throw new Exception\Forbidden('Not allowed');
    }
}
