<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Model;

class InodeLocks extends \Pop\Db\Record
{

    /*
     * int id
     * int inode_id Inode
     * varchar token
     * int created utctime
     * int expires utctime
     * int depth
     * int scope
     * varchar owner
     */

}