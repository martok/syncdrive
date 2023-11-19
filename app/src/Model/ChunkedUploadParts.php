<?php

namespace App\Model;

use App\ObjectStorage\ObjectInfo;

class ChunkedUploadParts extends \Pop\Db\Record
{

    /*
     * int id
     * int upload_id ChunkedUploads
     * varchar part
     * int size
     * varchar object ObjectStorage
     */

    public static function New(ChunkedUploads $upload, string $partIdent, ObjectInfo $object): static
    {
        return new static([
            'upload_id' => $upload->id,
            'part' => $partIdent,
            'size' => $object->size,
            'object' => $object->object,
        ]);
    }
}