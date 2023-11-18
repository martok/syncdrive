<?php

namespace App\Dav\FS;

use App\Dav\Perm;
use App\Model\ChunkedUploads;
use App\ObjectStorage\ObjectInfo;
use Pop\Db\Record\Collection;
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
            return $this->moveChunkedToFile($upload, $existingFile, $chunkInfo['name']);
        }
        return null;
    }

    private function findTargetFile(string $name): ?File
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

    private function moveChunkedToFile(ChunkedUploads $upload, ?File $existingFile, string $newName): string
    {
        // assemble object before starting transaction
        $object = $upload->assemble($this->ctx->storage);
        if (!$this->ctx->checkTransferredChecksums($object->checksums)) {
            throw new Exception\BadRequest('Received data did not match OC-Checksum');
        }
        // object is assembled, save file info
        ChunkedUploads::db()->beginTransaction();
        if ($existingFile) {
            $etag = self::UpdateFile($existingFile, $object);
        } else {
            $etag = self::CreateFileIn($this, $newName, $object);
        }
        $upload->deleteWithObjects($this->ctx->storage);
        ChunkedUploads::db()->commit();
        return $etag;
    }
}