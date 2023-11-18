<?php

namespace App\Dav\FS;

use App\Dav\TransferChecksums;
use App\Model\Inodes;
use App\ObjectStorage\ObjectInfo;
use Sabre\DAV\Exception;

trait FileUploadTrait
{
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