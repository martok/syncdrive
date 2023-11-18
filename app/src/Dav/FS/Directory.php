<?php

namespace App\Dav\FS;

use App\Dav\IIndexableCollection;
use App\Dav\NodeResolver;
use App\Dav\Perm;
use App\Model\ChunkedUploadParts;
use App\Model\ChunkedUploads;
use App\Model\Inodes;
use App\ObjectStorage\ObjectInfo;
use Sabre\DAV\Exception;
use Sabre\DAV\ICollection;
use Sabre\DAV\ICopyTarget;
use Sabre\DAV\IMoveTarget;
use Sabre\DAV\INode;

class Directory extends Node implements ICollection, IIndexableCollection, IMoveTarget, ICopyTarget
{
    use FileUploadTrait;
    use ChunkedUploadTrait;

    public function createRegularFile(string $name, $data): ?string
    {
        $this->requireInnerPerm(Perm::CAN_ADDFILE);
        if (!$this->ValidateFileName($name)) {
            throw new Exception\Forbidden('Invalid file name');
        }
        $object = $this->ctx->storeUploadedData($data);
        Inodes::db()->beginTransaction();
        $etag = self::CreateFileIn($this, $name, $object);
        Inodes::db()->commit();
        return $etag;
    }

    /**
     * @inheritDoc
     */
    public function createFile($name, $data = null)
    {
        if ($this->ctx->isChunkedV1Request()) {
            return $this->createChunkedV1File($name, $data);
        }
        return $this->createRegularFile($name, $data);
    }

    /**
     * @inheritDoc
     */
    public function createDirectory($name)
    {
        $this->requireInnerPerm(Perm::CAN_MKDIR);
        if (!$this->ValidateFileName($name)) {
            throw new Exception\Forbidden('Invalid file name');
        }
        Inodes::db()->beginTransaction();
        // create the new directory
        $node = Inodes::New(Inodes::TYPE_COLLECTION, $name, owner: $this->newItemOwner(false), parent: $this->getInodeId());
        $node->save();
        // reflect change on self
        $this->getInode()->contentChanged(save: true);
        Inodes::db()->commit();
    }

    private function childNodeFromInode(Inodes $inode): Node
    {
        $node = Node::FromInode($inode, $this->ctx);
        $node->inheritPerms($this->getInnerPerms());
        return $node;
    }

    /**
     * @inheritDoc
     */
    public function getChild($name)
    {
        if (!self::SplitQualifiedName($name, $basename, $inode)) {
            throw new Exception\BadRequest('Not a valid file name: '.$name);
        }
        if ($this->ctx->isChunkedV1Request() && ($info = ChunkedUploads::SplitV1Name($basename))) {
            // Return the target node (if it exists) for chunked requests. This is required for If-Match checks
            // to work correctly. The actual request handler uses Tree->nodeExists, which calls hasChildren().
            $basename = $info['name'];
        }
        $node = $this->getInode()->findChild($basename, $inode);
        if (is_null($node)) {
            throw new Exception\NotFound('File with name '.$basename.' could not be located');
        }
        return $this->childNodeFromInode($node);
    }

    /**
     * @inheritDoc
     */
    public function getChildren()
    {
        return array_map(function(Inodes $inode) {
            return $this->childNodeFromInode($inode);
        }, $this->getInode()->getChildren());
    }

    /**
     * Same as getChildren, but including deleted items. Sabre doesn't handle this gracefully, so only
     * use this method if everything behind it can handle items with same names but different inode ids.
     */
    public function getChildrenWithDeleted(): array
    {
        return array_map(function(Inodes $inode) {
            return $this->childNodeFromInode($inode);
        }, $this->getInode()->getChildren(true));
    }

    /**
     * @inheritDoc
     */
    public function childExists($name)
    {
        if (!self::SplitQualifiedName($name, $basename, $inode)) {
            throw new Exception\BadRequest('Not a valid file name: '.$name);
        }
        return !is_null($this->getInode()->findChild($basename, $inode));
    }

    public function hasChildren(): bool
    {
        return $this->getInode()->hasChildren();
    }

    protected function copyTo(Directory $parent, ?string $newName): void
    {
        $parentInode = $parent->getInode(true);
        // build target file name
        if (is_null($newName))
            $newName = $this->getName();
        $newName = NodeResolver::InodeIncrementalName($parentInode, $newName);
        if (!$this->ValidateFileName($newName)) {
            throw new Exception\Forbidden('Invalid file name');
        }
        // create and save a copy of our (real) inode
        $cloneInode = $this->getInode(true)->replicate([
            'parent_id' => (int)$parentInode->id,
            'owner_id' => $parent->newItemOwner(false),
            'name' => $newName,
            'modified' => time(),
        ]);
        // copy our contents as well
        $copyOfSelf = $parent->childNodeFromInode($cloneInode);
        assert($copyOfSelf instanceof Directory);
        foreach ($this->getChildren() as $child) {
            $child->copyTo($copyOfSelf, null);
        }
    }

    protected static function checkCopyMoveSemantics(bool $isMove, Directory $target, Node $source): void
    {
        // assuming the current state is completely valid, the only place where any of these rules
        // could be broken is here

        // "A received share can only exist in a tree-owned Directory"
        //   - a share anywhere below in the tree follows this rule (precondition)
        //   - the current node can not be moved to a Directory not tree-owned by the owner of the current node
        //     (not necessarily the current user, if moving files within a received share)
        if ($source->isLink()) {
            $sourceOwner = $source->getInode(false)->owner_id;
            if (!NodeResolver::InodeTreeOwnedBy($target->inode->id, $sourceOwner))
                throw new Exception\Forbidden('Shares can only be moved to collections owned by the same user.');
        }

        // "The tree does not contain loops"
        //   - no parent of $target must be inside $source
        //   - no parent of $target must be a shared folder that has a link inside $source
        if (NodeResolver::InodeVisibleIn($target->getInodeId(), $source->getInodeId()))
            throw new Exception\Conflict('The destination may not be part of the same subtree as the source path.');
    }

    /**
     * @inheritDoc
     */
    public function moveInto($targetName, $sourcePath, INode $sourceNode): bool
    {
        if (!$sourceNode instanceof Node)
            return false;
        $sourceNode->requirePerm(Perm::CAN_MOVE);
        $this->requireInnerPerm($sourceNode instanceof File ? Perm::CAN_ADDFILE : Perm::CAN_MKDIR);
        self::checkCopyMoveSemantics(true, $this, $sourceNode);
        // moving a node is just a simple change of parent for our Nodes
        Inodes::db()->beginTransaction();
        $prevParent = $sourceNode->inode->parent_id;
        $sourceNode->inode->parent_id = $this->getInodeId();
        // setName validates the name and file duplicates and throws on error, aborting the transaction, saves if OK
        $sourceNode->setName($targetName);
        // the new parent (this) was updated, also update the old parent
        if ($node = Inodes::Find($prevParent))
            $node->contentChanged(save: true);
        Inodes::db()->commit();
        return true;
    }

    public function copyInto($targetName, $sourcePath, INode $sourceNode): bool
    {
        if (!$sourceNode instanceof Node)
            return false;
        $sourceNode->requirePerm(Perm::CAN_MOVE);
        $this->requireInnerPerm($sourceNode instanceof File ? Perm::CAN_ADDFILE : Perm::CAN_MKDIR);
        self::checkCopyMoveSemantics(false, $this, $sourceNode);
        // recursively copy all in one transaction
        Inodes::db()->beginTransaction();
        $sourceNode->copyTo($this, $targetName);
        // changing the children changes the etag
        $this->getInode()->contentChanged(save: true);
        Inodes::db()->commit();
        return true;
    }


}