<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Model;

use App\ObjectStorage\ObjectInfo;
use App\ObjectStorage\ObjectStorage;
use Pop\Db\Record\Collection;
use Sabre\DAV\Exception;

class ChunkedUploads extends \Pop\Db\Record
{

    /*
     * int id
     * varchar transfer_id
     * int started utctime
     * int/null num_parts
     * int/nul total_length
     */

    public static function SplitV1Name(string $name): ?array
    {
        // transferid is numeric, but return it as string to avoid issues with int32
        if (preg_match('/^(.*?)-chunking-(\d+)-(\d+)-(\d+)$/', $name, $match)) {
            return [
                'name' => $match[1],
                'transfer' => $match[2],
                'count' => (int)$match[3],
                'part' => (int)$match[4],
            ];
        }
        return null;
    }

    public static function Find(string $transferId): ?self
    {
        if (($upload = self::findOne(['transfer_id' => $transferId])) &&
            !is_null($upload->id))
            return $upload;
        return null;
    }

    public static function NewV1(string $transfer, int $count): static
    {
        return new static([
            'transfer_id' => $transfer,
            'started' => time(),
            'num_parts' => $count,
        ]);
    }

    public static function NewV2(string $transfer, int $totalLength): static
    {
        return new static([
            'transfer_id' => $transfer,
            'started' => time(),
            'total_length' => $totalLength,
        ]);
    }

    public static function FindOrStartTransfer(string $transfer, ?int $partCount = null, ?int $totalLength = null): static
    {
        // find the existing transfer or create new record
        $upload = static::Find($transfer);
        if (!$upload) {
            if (!is_null($partCount)) {
                $upload = static::NewV1($transfer, $partCount);
            } elseif (!is_null($totalLength)) {
                $upload = static::NewV2($transfer, $totalLength);
            } else
                throw new Exception\BadRequest('Chunked upload version not recognized');
            $upload->save();
        }
        return $upload;
    }

    public function findParts(): Collection
    {
        return ChunkedUploadParts::findBy(['upload_id' => $this->id], ['order' => 'part ASC']);
    }

    public function countParts(): int
    {
        return ChunkedUploadParts::getTotal(['upload_id' => $this->id]);
    }

    public function saveChunk(string $partIdent, ObjectInfo $object, ObjectStorage $storage): ChunkedUploadParts
    {
        // if we already had data for that part, replace it
        $part = ChunkedUploadParts::findOne(['upload_id' => $this->id, 'part' => $partIdent]);
        if (!is_null($part->object)) {
            $storage->safeRemoveObject($part->object);
            $part->object = $object->object;
            $part->size = $object->size;
        } else {
            $part = ChunkedUploadParts::New($this, $partIdent, $object);
        }
        $part->save();
        return $part;
    }

    public function assemble(ObjectStorage $storage): ObjectInfo
    {
        $parts = $this->findParts();
        $expectedSize = array_sum($parts->toArray(['column' => 'size']));
        $partObjects = $parts->toArray(['column' => 'object']);
        if (!($object = $storage->assembleObject($partObjects)) ||
            ($object->size !== $expectedSize)) {
            throw new Exception\BadRequest('Failed to assemble object from chunks');
        }
        return $object;
    }

    public function deleteWithParts(ObjectStorage $storage): void
    {
        // remove all associated parts and objects
        foreach ($this->findParts() as $part) {
            $storage->safeRemoveObject($part->object);
            $part->delete();
        }
        $this->delete();
    }


}