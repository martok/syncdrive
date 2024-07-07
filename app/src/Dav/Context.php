<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav;

use App\Dav\FS\Directory;
use App\Dav\FS\Node;
use App\Dav\FS\VirtualRoot;
use App\Manager;
use App\Model\Inodes;
use App\ObjectStorage\IStorageBackend;
use App\ObjectStorage\ObjectInfo;
use App\ObjectStorage\ObjectStorage;
use Nepf2\Application;
use Nepf2\Util\ClassUtil;
use Sabre\DAV\Exception;
use Sabre\DAV\Tree;

class Context
{
    public readonly ?ObjectStorage $storage;
    private ?Tree $filesView;
    private bool $isReadonly;

    public function __construct(
        public Application $app,
        public Identity $identity
    )
    {
        $this->isReadonly = $app->cfg('site.readonly') || Manager::InMaintenanceMode($app);
    }

    public function setupStorage(): void
    {
        $storageCfg = $this->app->cfg('storage');

        $this->storage = new ObjectStorage();
        $this->storage->setChunkSize(ini_parse_quantity($storageCfg['chunkSize'] ?? '1M'));
        $this->storage->setChecksumOCAlgos($storageCfg['checksums']);

        foreach ($storageCfg['backends'] as $backendsCfg) {
            $backendClass = $backendsCfg['class'];
            if (!ClassUtil::IsClass($backendClass) ||
                !ClassUtil::ImplementsInterface($backendClass, IStorageBackend::class)) {
                throw new \App\Exception("Configured storage class {$backendsCfg['class']} not found or invalid!");
            }
            $backend = new $backendClass($this->app, $backendsCfg['config']);
            $this->storage->addBackend($backend, $backendsCfg['intent']);
        }
    }

    private function createFilesView(): Tree
    {
        switch ($this->identity->type) {
            case Identity::TYPE_USER:
                $root = Node::FromInode($this->identity->user->root(), $this);
                assert($root instanceof Directory && $root instanceof IACLTarget);
                $root->inheritPerms(new PermSet(Perm::DEFAULT_OWNED));
                return new Tree($root);
            case Identity::TYPE_SHARE:
                $share = $this->identity->share;
                $perm = new PermSet($share->permissions ?? '');
                if ($inode = Inodes::Find($share->inode_id)) {
                    $node = Node::FromInode($inode, $this);
                    if (!$node instanceof Directory)
                        $node = new VirtualRoot($this, [$node]);
                    $node->inheritPerms($perm);
                    return new Tree($node);
                }
                /* fallthrough */
            case Identity::TYPE_UNAUTHENTICATED:
            default:
                return new Tree(new VirtualRoot($this));
        }
    }

    public function getFilesView(): Tree
    {
        return $this->filesView ??= $this->createFilesView();
    }

    public function getNode(string $uri, bool $disallowRoot=true): ?Node
    {
        $tree = $this->getFilesView();
        try {
            $node = $tree->getNodeForPath($uri);
            if (!($node instanceof Node))
                // this method intentionally returns null for VirtualRoot
                return null;
            if ($disallowRoot && !$node->getInode(false)->parent_id)
                return null;
            return $node;
        } catch (Exception\NotFound) {
            return null;
        }
    }

    public function canAccessInode(int $inodeId): bool
    {
        switch ($this->identity->type) {
            case Identity::TYPE_USER:
                return NodeResolver::InodeVisibleIn($inodeId, $this->identity->user->root()->id);
            case Identity::TYPE_SHARE:
                $share = $this->identity->share;
                return NodeResolver::InodeVisibleIn($inodeId, $share->inode_id);
            case Identity::TYPE_UNAUTHENTICATED:
            default:
                // A file accessible via any public shared folder should also return true for anonymous access, but
                // checking that is potentially extremely complex.
                return false;
        }
    }

    /**
     * If the app settings limit modification, remove all permissions but
     * keep flags intact.
     */
    public function filterPermissions(PermSet $effectivePermissions): PermSet
    {
        if ($this->isReadonly)
            return $effectivePermissions->without(Perm::INHERITABLE_MASK);
        return $effectivePermissions;
    }

    public function forceDownloadResponse(): bool
    {
        $req = $this->app->request();
        if ($req->int('dl') === 1)
            return true;
        if ($req->int('raw') === 1)
            return false;
        return true;
    }

    public function isChunkedV1Request(): bool
    {
        return !is_null($this->app->request()->getHeader('OC-Chunked'));
    }

    public function OCRequestMTime(): ?int
    {
        $ts = $this->app->request()->getHeader('X-OC-MTime');
        return is_null($ts) ? null : (int)$ts;
    }

    private function checkTransferredLength(int $received): bool
    {
        $req = $this->app->request();
        if (!is_null($expect = $req->getHeader('content-length'))) {
            return $received == (int)$expect;
        }
        return true;
    }

    public function checkTransferredChecksums(array $checksums): bool
    {
        $req = $this->app->request();
        if (!is_null($expect = $req->getHeader('OC-Checksum'))) {
            // If the type of the checksum is not understood or supported by the client or by the
            // server then the checksum should be ignored.
            if (TransferChecksums::SplitHeader($expect, $alg, $hash) &&
                isset($checksums[$alg])) {
                return 0 == strcasecmp($checksums[$alg], $hash);
            }
        }
        return true;
    }

    public function storeUploadedData(mixed $data, bool $checkLength = true, bool $checkChecksum = true): ObjectInfo
    {
        $object = $this->storage->storeNewObject($data);
        if ($checkLength && !$this->checkTransferredLength($object->size)) {
            throw new Exception\BadRequest('Received data did not match content-length');
        }
        if ($checkChecksum && !$this->checkTransferredChecksums($object->checksums)) {
            throw new Exception\BadRequest('Received data did not match OC-Checksum');
        }
        return $object;
    }
}
