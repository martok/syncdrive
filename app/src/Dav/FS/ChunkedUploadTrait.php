<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav\FS;

use App\Dav\Perm;
use App\Model\ChunkedUploads;
use App\Thumbnail\ThumbnailService;
use Sabre\DAV\Exception;

/**
 * This trait exists only to improve readability of Directory, its only user.
 *
 */
trait ChunkedUploadTrait
{
    public function createChunkedV1File(string $name, $data): ?string
    {
        $chunkInfo = ChunkedUploads::SplitV1Name($name);
        if (is_null($chunkInfo) ||
            ($chunkInfo['part'] >= $chunkInfo['count'])) {
            throw new Exception\BadRequest('Invalid chunk file name');
        }
        $existingFile = $this->findTargetFile($chunkInfo['name']);
        $object = $this->ctx->storeUploadedData($data, checkChecksum: false);
        ChunkedUploads::db()->beginTransaction();
        $upload = ChunkedUploads::FindOrStartTransfer($chunkInfo['transfer'], partCount: $chunkInfo['count']);
        $upload->saveChunk($chunkInfo['part'], $object, $this->ctx->storage);
        ChunkedUploads::db()->commit();

        // transaction is closed, now check if we have all parts and if so, assemble
        if ($upload->countParts() == $upload->num_parts) {
            $etag = $this->moveChunkedToFile($upload, $existingFile, $chunkInfo['name']);
            File::AddUploadHeaders($this->getChild($chunkInfo['name']));
            return $etag;
        }
        return null;
    }

    public function findTargetFile(string $name): ?File
    {
        // check if this is a PUT to an existing file
        try {
            $file = $this->getChild($name);
            $file->requireInnerPerm(Perm::CAN_WRITE);
            assert($file instanceof File);
            return $file;
        } catch (Exception\NotFound) {
            $this->requireInnerPerm(Perm::CAN_ADDFILE);
            if (!$this->ValidateFileName($name)) {
                throw new Exception\Forbidden('Invalid file name');
            }
            return null;
        }
    }

    public function moveChunkedToFile(ChunkedUploads $upload, ?File $existingFile, string $newName): string
    {
        // assemble object before starting transaction
        $object = $upload->assemble($this->ctx->storage);
        if (!$this->ctx->checkTransferredChecksums($object->checksums)) {
            throw new Exception\BadRequest('Received data did not match OC-Checksum');
        }
        // object is assembled, save file info
        ChunkedUploads::db()->beginTransaction();
        if ($existingFile) {
            $newName = $existingFile->getName();
            $etag = self::UpdateFile($existingFile, $object);
        } else {
            $etag = self::CreateFileIn($this, $newName, $object);
        }
        $upload->deleteWithParts($this->ctx->storage);
        ChunkedUploads::db()->commit();
        (new ThumbnailService($this->ctx))->maybeCreateThumbnail($object, $newName);
        return $etag;
    }
}