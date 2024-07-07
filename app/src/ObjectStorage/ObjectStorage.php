<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

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

    const INTENT_STORAGE = 1 << 1;
    const INTENT_TEMPORARY = 1 << 2;
    const BACKEND_INTENTS = [
        'storage' => self::INTENT_STORAGE,
        'temporary' => self::INTENT_TEMPORARY,
    ];

    /** @var BackendDefinition[] */
    private array $backends = [];
    private int $chunkSize = self::CHUNK_SIZE_DEFAULT;
    private array $checksumOCAlgos = [];

    public static function CountObjectUsers(string $object): int
    {
        return FileVersions::getTotal(['object' => $object]) +
               ChunkedUploadParts::getTotal(['object' => $object]) +
               Thumbnails::getTotal(['object' => $object]);
    }

    public function __construct()
    {
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

    public function addBackend(IStorageBackend $backend, array $intents): void
    {
        $intentInt = 0;
        foreach ($intents as $intent) {
            if (!isset(self::BACKEND_INTENTS[$intent]))
                throw new Exception('Invalid backend intent: ' . $intent);
            $intentInt |= self::BACKEND_INTENTS[$intent];
        }
        $this->backends[] = new BackendDefinition($intentInt, $backend);
    }

    public function getBackends(): array
    {
        return $this->backends;
    }

    private function getTempWriter(): IObjectWriter
    {
        foreach ($this->backends as $bd) {
            if ($bd->isIntent(self::INTENT_TEMPORARY)) {
                $tmpName = $this->generateTempIdent();
                return $bd->backend->openWriter($tmpName);
            }
        }
        throw new Exception('No storage backend for temporary files configured');
    }

    private function getReader(string $object): mixed
    {
        foreach ($this->backends as $bd) {
            if ($bd->backend->objectExists($object))
                return $bd->backend->openReader($object);
        }
        return null;
    }

    public function objectCopy(string $sourceObj, IStorageBackend $sourceBackend,
                               string $destObj, IStorageBackend $destBackend,
                               bool $deleteOnSuccess): bool
    {
        $reader = $sourceBackend->openReader($sourceObj);
        try {
            $wrapWriter = new StreamObjectWriter($destBackend->openWriter($destObj), $this->chunkSize, []);
            try {
                $copied = Stream::CopyToStream($reader, $wrapWriter->getStream());
            } finally {
                fclose($wrapWriter->getStream());
            }
        } finally {
            fclose($reader);
        }
        // must have copied more than for an EMPTY_OBJECT (which is not stored) to be a success
        if ($copied > 0) {
            if ($deleteOnSuccess) {
                return $sourceBackend->removeObject($sourceObj);
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    private function moveObject(string $source, string $dest): bool
    {
        $sourceBack = null;
        $destBack = null;
        foreach ($this->backends as $bd) {
            if ($bd->backend->objectExists($source)) {
                $sourceBack = $bd->backend;
                break;
            }
        }
        if (is_null($sourceBack))
            throw new Exception('Source file not found');
        foreach ($this->backends as $bd) {
            if ($bd->isIntent(self::INTENT_STORAGE)) {
                $destBack = $bd->backend;
                break;
            }
        }
        if (is_null($destBack))
            throw new Exception('No storage backend for files configured');

        if ($destBack === $sourceBack) {
            // fast path: in the same backend, we can often use renames
            if ($sourceBack->moveObject($source, $dest))
                return true;
        }

        // if the fast path is not available or not successful, copy and remove the source
        return $this->objectCopy($source, $sourceBack, $dest, $destBack, true);
    }

    private function removeObject(string $object): int
    {
        $count = 0;
        foreach ($this->backends as $bd) {
            if ($bd->backend->removeObject($object))
                $count++;
        }
        return $count;
    }

    private function internalStore(callable $writeMethod): ObjectInfo
    {
        $writer = $this->getTempWriter();
        try {
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
                $writer->getBackend()->removeObject($writer->getObject());
            } else {
                $persistentName = $this->generateObjectIdent($info);
                if (!$this->moveObject($writer->getObject(), $persistentName))
                    throw new Exception('Failed to move file from temporary to storage');
                $info = $info->withName($persistentName);
            }
            return $info;
        } catch (\Exception $e) {
            // in case of errors, make sure the temp file is removed
            $backend = $writer->getBackend();
            $object = $writer->getObject();
            if ($hashWriter->hasStream())
                @fclose($hashWriter->getStream());
            $backend->removeObject($object);
            throw $e;
        }
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
        $reader = $this->getReader($object);
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
        if ($result = ($this->removeObject($object) > 0)) {
            $result = $result && ThumbnailService::RemoveObjectThumbnails($this, $object);
        }
        return $result;
    }

    private function generateTempIdent(): string
    {
        $seed = date('r') . microtime();
        return 'tmp_' . hash(self::OBJECT_HASH_ALG, $seed);
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