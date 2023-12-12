<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

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

}