<?php

namespace App\Dav\FS;

use App\Dav\TransferChecksums;
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

    private function checkTransferredChecksums(array $checksums): bool
    {
        $req = $this->ctx->app->request();
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

    private function storeUploadedData(mixed $data, bool $checkLength = true, bool $checkChecksum = true): ObjectInfo
    {
        $object = $this->ctx->storage->storeNewObject($data);
        if ($checkLength && !$this->checkTransferredLength($object->size)) {
            throw new Exception\BadRequest('Received data did not match content-length');
        }
        if ($checkChecksum && !$this->checkTransferredChecksums($object->checksums)) {
            throw new Exception\BadRequest('Received data did not match OC-Checksum');
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