<?php

namespace App\Model;

class InodeProps extends \Pop\Db\Record
{
    const TYPE_STRING = 1;
    const TYPE_XML = 2;
    const TYPE_OBJECT = 3;

    /*
     * int id
     * int inode_id Inode
     * varchar name
     * int type
     * text value
     */

}