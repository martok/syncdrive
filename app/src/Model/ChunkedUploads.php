<?php

namespace App\Model;

use App\ObjectStorage\ObjectInfo;
use App\ObjectStorage\ObjectStorage;
use Sabre\DAV\Exception;
use Pop\Db\Record\Collection;

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

    public static function NewV1(int $transfer, int $count): static
    {
        return new static([
            'transfer_id' => $transfer,
            'started' => time(),
            'num_parts' => $count,
        ]);
    }

    public static function FindOrStartTransfer(string $transferid, ?int $partCount = null): static
    {
        // find the existing transfer or create new record
        $upload = static::Find($transferid);
        if (!$upload) {
            if (!is_null($partCount)) {
                $upload = static::NewV1($transferid, $partCount);
                $upload->save();
            } else
                throw new Exception\BadRequest('Chunked upload version not recognized');
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

    public function saveChunk(int $partNo, ObjectInfo $object, ObjectStorage $storage): ChunkedUploadParts
    {
        // if we already had data for that part, replace it
        $part = ChunkedUploadParts::findOne(['upload_id' => $this->id, 'part' => $partNo]);
        if (!is_null($part->object)) {
            $storage->removeObject($part->object);
            $part->object = $object->object;
            $part->size = $object->size;
        } else {
            $part = ChunkedUploadParts::New($this, $partNo, $object);
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

    public function deleteWithObjects(ObjectStorage $storage): void
    {
        // remove all associated parts and objects
        foreach ($this->findParts() as $part) {
            $storage->removeObject($part->object);
            $part->delete();
        }
        $this->delete();
    }


}