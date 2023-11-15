<?php

namespace App\Dav;

use App\Model\ChunkedUploads;
use App\ObjectStorage\ObjectInfo;
use Pop\Db\Record\Collection;
use Sabre\DAV\Exception;

trait ChunkedUploadTrait
{
    use FileUploadTrait;

    private function findTargetFile(string $name): ?File
    {
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

    private function findOrStartTransfer(string $transferid, ?int $partCount = null): ChunkedUploads
    {
        // find the existing transfer or create new record
        $upload = ChunkedUploads::Find($transferid);
        if (!$upload) {
            if (!is_null($partCount)) {
                $upload = ChunkedUploads::NewV1($transferid, $partCount);
                $upload->save();
            } else
                throw new Exception\BadRequest('Chunked upload version not recognized');
        }
        return $upload;
    }

    private function assembleChunkedUpload(Collection $parts): ObjectInfo
    {
        $expectedSize = array_sum($parts->toArray(['column' => 'size']));
        $partObjects = $parts->toArray(['column' => 'object']);
        if (!($object = $this->ctx->storage->assembleObject($partObjects)) ||
            ($object->size !== $expectedSize)) {
            throw new Exception\BadRequest('Failed to assemble object from chunks');
        }
        return $object;
    }

    private function moveChunkedToFile(ChunkedUploads $upload, ?File $existingFile, string $newName): string
    {
        // assemble object before starting transaction
        $object = $this->assembleChunkedUpload($upload->findParts());
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