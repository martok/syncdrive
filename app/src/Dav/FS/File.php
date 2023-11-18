<?php

namespace App\Dav\FS;

use App\Dav\NodeResolver;
use App\Dav\Perm;
use App\Dav\TransferChecksums;
use App\Model;
use App\ObjectStorage\ObjectStorage;
use Elephox\Mimey\MimeType;
use Sabre\DAV\Exception;
use Sabre\DAV\IFile;

class File extends Node implements IFile
{
    use FileUploadTrait;

    protected ?Model\FileVersions $boundVersion = null;

    /**
     * @inheritDoc
     */
    public function put($data)
    {
        // follow permissions to shared inodes
        $this->requireInnerPerm(Perm::CAN_WRITE);
        $object = $this->ctx->storeUploadedData($data);
        Model\Inodes::db()->beginTransaction();
        $etag = self::UpdateFile($this, $object);
        Model\Inodes::db()->commit();
        return $etag;
    }

    /**
     * @inheritDoc
     */
    public function get()
    {
        $this->ensureBoundVersion();
        $object = $this->boundVersion->object;
        // shortcut for the 0-byte file
        if ($object === ObjectStorage::EMPTY_OBJECT)
            return '';
        $stream = $this->ctx->storage->openReader($object);
        if (!is_resource($stream))
            throw new Exception\NotFound('File data not found');
        if (!is_null($csstr = $this->boundVersion->hashes) &&
            ($hashes = TransferChecksums::Unserialize($csstr)) &&
            ($header = TransferChecksums::FormatDownloadHeader($hashes, $this->ctx->app->cfg('storage.checksums')))) {
            $this->ctx->app->response()->setHeader('OC-Checksum', $header);
        }
        return $stream;
    }

    /**
     * @inheritDoc
     */
    public function getContentType()
    {
        // unless we want to show the file inline, leave at the default of application/octet-stream
        if (!$this->ctx->forceDownloadResponse())
            return $this->guessContentType();
        return null;
    }

    public function guessContentType(): ?string
    {
        if (($ext = pathinfo($this->getName(), PATHINFO_EXTENSION)) &&
            ($mime = MimeType::tryFromExtension($ext)))
            return $mime->value;
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSize()
    {
        $this->ensureBoundVersion();
        return $this->boundVersion->size;
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
        // make a copy of the current version only
        $cloneVersion = $this->getCurrentVersion()->replicate([
            'created' => time(),
            'creator_id' => $this->newItemOwner(true),
        ]);

        // create and save a copy of our inode, pointing to the new version
        $cloneInode = $this->getInode(true)->replicate([
            'parent_id' => (int)$parentInode->id,
            'owner_id' => $parent->newItemOwner(false),
            'name' => $newName,
            'modified' => time(),
            'current_version_id' => (int)$cloneVersion->id,
        ]);

        // tell the new version what it refers to
        $cloneVersion->inode_id = (int)$cloneInode->id;
        $cloneVersion->save();
    }

    protected function internalRemove(): void
    {
        $versions = $this->getVersions();
        foreach ($versions as $version) {
            $this->removeVersion($version);
        }
        parent::internalRemove();
    }


    protected function ensureBoundVersion()
    {
        if ($this->boundVersion)
            return;
        $this->boundVersion = $this->getCurrentVersion();
    }

    public function setBoundVersion(Model\FileVersions $version)
    {
        if ($this->boundVersion)
            throw new \App\Exception("Inode {$this->getInodeId()} has bound version");
        $this->boundVersion = $version;
    }

    public function getCurrentVersion(): Model\FileVersions
    {
        return $this->getInode()->getCurrentVersion();
    }

    /**
     * @return Model\FileVersions[]
     */
    public function getVersions(): array
    {
        $list = Model\FileVersions::findBy(['inode_id' => $this->getInodeId()], ['order' => 'created DESC']);
        return $list->getItems();
    }

    /**
     * Get a specific version's information, specified by ID and Timestamp.
     *
     * @param int $version
     * @param int $timestamp
     * @return Model\FileVersions|null
     */
    public function getVersion(int $version, int $timestamp): ?Model\FileVersions
    {
        $current = Model\FileVersions::findOne(['id' => $version, 'created' => $timestamp,
                                                'inode_id' => $this->getInodeId()]);
        if (!isset($current->id))
            return null;
        return $current;
    }

    public function restoreVersion(Model\FileVersions $version): bool
    {
        if ($version->inode_id !== $this->getInodeId())
            return false;

        $inode = $this->getInode();

        Model\Inodes::db()->beginTransaction();
        $this->boundVersion = null;
        // reflect change on self
        $inode->current_version_id = $version->id;
        $inode->contentChanged();
        $inode->save();
        Model\Inodes::db()->commit();
        return true;
    }

    public function removeVersion(Model\FileVersions $version): bool
    {
        if ($version->inode_id !== $this->getInodeId())
            return false;

        if (Model\FileVersions::CountObjectUsers($version->object) == 1) {
            if (!$this->ctx->storage->removeObject($version->object))
                return false;
        }
        $version->delete();
        return true;
    }
}