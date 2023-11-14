<?php

namespace App\Model;

class Users extends \Pop\Db\Record\Encoded
{
    protected array $hashFields      = ['password'];
    protected string $hashAlgorithm   = PASSWORD_BCRYPT;
    protected array $hashOptions     = ['cost' => 8];

    /*
     * int id
     * varchar(255) username
     * varchar(255) password
     *
     */

    public function idStr(): string
    {
        return sprintf('uid:%d', $this->id);
    }

    public function root(): ?Inodes
    {
        $r = Inodes::findOne(['owner_id' => $this->id, 'parent_id' => null, 'type' => Inodes::TYPE_COLLECTION]);
        if (isset($r->id))
            return $r;
        return null;
    }
}
