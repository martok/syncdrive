<?php

namespace App\Model;

class FileVersions extends \Pop\Db\Record
{

    /*
     * int id
     * int inode_id Inode
     * int created utctime
     * int size
     * varchar object ObjectStorage
     * varchar/null name
     */

    public static function New(Inodes $inode, int $size, string $object, int $creator): static
    {
        return new static([
            'inode_id' => $inode->id,
            'created' => time(),
            'creator_id' => $creator,
            'size' => $size,
            'object' => $object
        ]);
    }

    public static function CountObjectUsers(string $object): int
    {
        return FileVersions::getTotal(['object' => $object]);
    }
}