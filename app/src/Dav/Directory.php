<?php

namespace App\Dav;

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

    private function checkTransferredLength(int $received): bool
    {
        $req = $this->ctx->app->request();
        if (!is_null($expect = $req->getHeader('content-length'))) {
            return $received == (int)$expect;
        }
        return true;
    }

    private function saveNewFile(string $name, ObjectInfo $object): string
    {
        // create an incomplete file first, then update with new version
        $inode = Inodes::New(Inodes::TYPE_FILE, $name, owner: $this->newItemOwner(false), parent: $this->getInodeId());
        $inode->save();
        $inode->newVersion($object, $this->newItemOwner(true), $this->ctx->OCRequestMTime());
        return sprintf('"%s"', $inode->etag);
    }

    private function saveUploadChunk(ChunkedUploads $upload, int $partNo, ObjectInfo $object): void
    {
        // if we already had data for that part, replace it
        $part = ChunkedUploadParts::findOne(['upload_id' => $upload->id, 'part' => $partNo]);
        if (!is_null($part->object)) {
            $this->ctx->storage->removeObject($part->object);
            $part->object = $object->object;
            $part->size = $object->size;
        } else {
            $part = ChunkedUploadParts::New($upload, $partNo, $object);
        }
        $part->save();
    }

    public function createRegularFile(string $name, $data): ?string
    {
        $this->requireInnerPerm(Perm::CAN_ADDFILE);
        if (!$this->ValidateFileName($name)) {
            throw new Exception\Forbidden('Invalid file name');
        }
        $object = $this->ctx->storage->storeNewObject($data);
        if (!$this->checkTransferredLength($object->size)) {
            throw new Exception\BadRequest('Received data did not match content-length');
        }
        Inodes::db()->beginTransaction();
        $etag = $this->saveNewFile($name, $object);
        Inodes::db()->commit();
        return $etag;
    }

    public function createChunkedV1File(string $name, $data): ?string
    {
        $chunkInfo = ChunkedUploads::SplitV1Name($name);
        if (is_null($chunkInfo) ||
            ($chunkInfo['part'] >= $chunkInfo['count'])) {
            throw new Exception\BadRequest('Invalid chunk file name');
        }
        // check if this is a PUT to an existing file
        try {
            $existingFile = $this->getChild($name);
            $existingFile->requireInnerPerm(Perm::CAN_WRITE);
        } catch (Exception\NotFound) {
            $existingFile = null;
            $this->requireInnerPerm(Perm::CAN_ADDFILE);
            if (!$this->ValidateFileName($chunkInfo['name'])) {
                throw new Exception\Forbidden('Invalid file name');
            }
        }
        $object = $this->ctx->storage->storeNewObject($data);
        if (!$this->checkTransferredLength($object->size)) {
            throw new Exception\BadRequest('Received data did not match content-length');
        }
        ChunkedUploads::db()->beginTransaction();
        // find the existing transfer or create new record
        $upload = ChunkedUploads::Find($chunkInfo['transfer']);
        if (!$upload) {
            $upload = ChunkedUploads::NewV1($chunkInfo['transfer'], $chunkInfo['count']);
            $upload->save();
        }
        $this->saveUploadChunk($upload, $chunkInfo['part'], $object);
        ChunkedUploads::db()->commit();

        // transaction is closed, now check if we have all parts and if so, assemble
        $parts = $upload->findParts();
        if ($parts->count() == $upload->num_parts) {
            $expectedSize = array_sum($parts->toArray(['column' => 'size']));
            $partObjects = $parts->toArray(['column' => 'object']);
            if (!($object = $this->ctx->storage->assembleObject($partObjects)) ||
                ($object->size !== $expectedSize)) {
                throw new Exception\BadRequest('Failed to assemble object from chunks');
            }
            // object is assembled, save file info
            ChunkedUploads::db()->beginTransaction();
            if ($existingFile) {
                // this is actually a PUT on an existing file
                $inode = $existingFile->getInode();
                $inode->newVersion($object, $this->newItemOwner(true), $this->ctx->OCRequestMTime());
                $etag = sprintf('"%s"', $inode->etag);
            } else {
                $etag = $this->saveNewFile($chunkInfo['name'], $object);
            }
            $upload->deleteWithObjects($this->ctx->storage);
            ChunkedUploads::db()->commit();
            return $etag;
        }
        return null;
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
            // required for If-Match checks must apply to the base file, not the decorated name
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