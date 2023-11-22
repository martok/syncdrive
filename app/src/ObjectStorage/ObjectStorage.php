<?php

namespace App\ObjectStorage;

use App\Dav\TransferChecksums;
use App\Exception;
use App\Model\ChunkedUploadParts;
use App\Model\FileVersions;
use App\Model\Thumbnails;
use App\Streams\Stream;
use App\Thumbnail\ThumbnailService;

class ObjectStorage
{
    const EMPTY_OBJECT = '';

    const OBJECT_HASH_ALG = 'sha256';
    const CHUNK_SIZE_DEFAULT = 64 << 20;
    const CHUNK_SIZE_MIN = 1 << 10;

    private IStorageBackend $backend;
    private int $chunkSize = self::CHUNK_SIZE_DEFAULT;
    private array $checksumOCAlgos = [];

    public static function CountObjectUsers(string $object): int
    {
        return FileVersions::getTotal(['object' => $object]) +
               ChunkedUploadParts::getTotal(['object' => $object]) +
               Thumbnails::getTotal(['object' => $object]);
    }

    public function __construct(IStorageBackend $backend)
    {
        $this->backend = $backend;
    }

    public function setChunkSize(int $chunkSize): void
    {
        if ($chunkSize === 0)
            $chunkSize = self::CHUNK_SIZE_DEFAULT;
        if ($chunkSize < self::CHUNK_SIZE_MIN)
            throw new Exception('Chunksize too small: ' . $chunkSize);
        $this->chunkSize = $chunkSize;
    }

    public function setChecksumOCAlgos(array $algos): void
    {
        $this->checksumOCAlgos = $algos;
    }

    private function internalStore(callable $writeMethod): ObjectInfo
    {
        $writer = $this->backend->openTempWriter();
        $hashWriter = new StreamObjectWriter($writer, $this->chunkSize, array_merge([self::OBJECT_HASH_ALG],
                                             TransferChecksums::OC2php($this->checksumOCAlgos)));
        $writeMethod($hashWriter);
        $size = ftell($hashWriter->getStream());
        fclose($hashWriter->getStream());

        $objectHash = $hashWriter->getHash();
        $objectChecksums = [];
        foreach ($this->checksumOCAlgos as $algo) {
            $objectChecksums[$algo] = $hashWriter->getHash(TransferChecksums::OC2php($algo));
        }
        $info = new ObjectInfo(self::EMPTY_OBJECT, $size, $objectHash, $this->chunkSize, $objectChecksums);
        if ($size === 0) {
            $this->backend->removeTemporary($writer);
        } else {
            $persistentName = $this->generateObjectIdent($info);
            $info = new ObjectInfo($persistentName, $size, $objectHash, $this->chunkSize, $objectChecksums);
            $this->backend->makePermanent($info, $writer);
        }
        return $info;
    }

    /**
     * Ingest request data into a new object and return its info.
     *
     * @param resource|string|null $dataSource File contents
     * @return ObjectInfo The new object's metadata
     */
    public function storeNewObject(mixed $dataSource): ObjectInfo
    {
        return $this->internalStore(function (StreamObjectWriter $hashWriter) use ($dataSource) {
            Stream::CopyToStream($dataSource, $hashWriter->getStream());
        });
    }

    /**
     * Take the given objects and concatenate them into a new object.
     *
     * @param string[] $sourceObjects array of object identifers
     * @return ObjectInfo The new object's metadata
     */
    public function assembleObject(array $sourceObjects): ObjectInfo
    {
        return $this->internalStore(function (StreamObjectWriter $hashWriter) use ($sourceObjects) {
            foreach ($sourceObjects as $source) {
                if ($source === self::EMPTY_OBJECT)
                    continue;
                $reader = $this->openReader($source);
                Stream::CopyToStream($reader, $hashWriter->getStream());
                fclose($reader);
            }
        });
    }

    /**
     * Open object for reading, return stream
     *
     * @param string $object
     * @return resource
     */
    public function openReader(string $object): mixed
    {
        $reader = $this->backend->openReader($object);
        assert(is_null($reader) || Stream::IsStream($reader));
        return $reader;
    }

    /**
     * Remove an object from the object storage, iff there is no or exactly one reference to it.
     * This can be called before removing the last DB record pointing to it.
     *
     * Return false on error and true otherwise.
     *
     * @param string $object
     * @return bool
     */
    public function safeRemoveObject(string $object): bool
    {
        if ($object === self::EMPTY_OBJECT)
            return true;
        if (self::CountObjectUsers($object) > 1)
            return true;
        if ($result = $this->backend->removeObject($object)) {
            $result = $result && ThumbnailService::RemoveObjectThumbnails($this, $object);
        }
        return $result;
    }

    private function generateObjectIdent(ObjectInfo $info): string
    {
        // the name must be filesystem-safe and <128 chars in total
        return implode('_', [
            // 40 chars
            substr($info->hash, 0, 40),
            // ~ 9 chars
            dechex($info->size),
            // ~ 6 chars
            dechex($info->chunkSize),
        ]);
    }
}