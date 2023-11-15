<?php

namespace App\Dav\FS;

use App\Model\Inodes;
use App\ObjectStorage\ObjectInfo;
use Sabre\DAV\Exception;

trait FileUploadTrait
{
    private function checkTransferredLength(int $received): bool
    {
        $req = $this->ctx->app->request();
        if (!is_null($expect = $req->getHeader('content-length'))) {
            return $received == (int)$expect;
        }
        return true;
    }

    private function storeUploadedData(mixed $data, bool $throwOnLength=true): ObjectInfo
    {
        $object = $this->ctx->storage->storeNewObject($data);
        if ($throwOnLength && !$this->checkTransferredLength($object->size)) {
            throw new Exception\BadRequest('Received data did not match content-length');
        }
        return $object;
    }

    private static function UpdateFile(File $file, ObjectInfo $content): string
    {
        $inode = $file->getInode();
        $inode->newVersion($content, $file->newItemOwner(true), $file->ctx->OCRequestMTime());
        return sprintf('"%s"', $inode->etag);
    }

    private static function CreateFileIn(Directory $owner, string $name, ObjectInfo $content): string
    {
        // create an incomplete file first, then update with new version
        $inode = Inodes::New(Inodes::TYPE_FILE, $name, owner: $owner->newItemOwner(false), parent: $owner->getInodeId());
        $inode->save();
        $inode->newVersion($content, $owner->newItemOwner(true), $owner->ctx->OCRequestMTime());
        return sprintf('"%s"', $inode->etag);
    }

}