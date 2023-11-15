<?php

namespace App\Model;

use App\ObjectStorage\ObjectStorage;
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

    public static function Find(string $transferId): ?self
    {
        if (($upload = self::findOne(['transfer_id' => $transferId])) &&
            !is_null($upload->id))
            return $upload;
        return null;
    }

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

    public static function NewV1(int $transfer, int $count): static
    {
        return new static([
            'transfer_id' => $transfer,
            'started' => time(),
            'num_parts' => $count,
        ]);
    }

    public function findParts(): Collection
    {
        return ChunkedUploadParts::findBy(['upload_id' => $this->id], ['order' => 'part ASC']);
    }

    public function countParts(): int
    {
        return ChunkedUploadParts::getTotal(['upload_id' => $this->id]);
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