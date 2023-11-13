<?php

namespace App\ObjectStorage;

use App\Streams\Stream;
use App\Exception;

class ObjectStorage
{
    const EMPTY_OBJECT = '';

    const CHUNK_SIZE_DEFAULT = 64 << 20;
    const CHUNK_SIZE_MIN = 1 << 10;

    private IStorageBackend $backend;
    private int $chunkSize = self::CHUNK_SIZE_DEFAULT;

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

    private function internalStore(callable $writeMethod): ObjectInfo
    {
        $writer = $this->backend->openTempWriter();
        $hashWriter = new StreamObjectWriter($writer, $this->chunkSize);
        $writeMethod($hashWriter);
        $size = ftell($hashWriter->getStream());
        fclose($hashWriter->getStream());

        $info = new ObjectInfo(self::EMPTY_OBJECT, $size, $hashWriter->getHash(), $this->chunkSize);
        if ($size === 0) {
            $this->backend->removeTemporary($writer);
        } else {
            $persistentName = $this->generateObjectIdent($info);
            $info = new ObjectInfo($persistentName, $size, $hashWriter->getHash(), $this->chunkSize);
            $this->backend->makePermanent($info, $writer);
        }
        return $info;
    }

    /**
     * Ingest request data into a new object and return its id (and the writer).
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
     * Take the given objects and concatenated them into a new object.
     *
     * @param array $sourceObjects array of object identifers
     * @return ObjectInfo The new object's metadata
     */
    public function assembleObject(array $sourceObjects): ObjectInfo
    {
        return $this->internalStore(function (StreamObjectWriter $hashWriter) use ($sourceObjects) {
            foreach ($sourceObjects as $source) {
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

    public function removeObject(string $object): bool
    {
        if ($object === self::EMPTY_OBJECT)
            return true;
        return $this->backend->removeObject($object);
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