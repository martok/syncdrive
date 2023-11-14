<?php

use App\Model\Inodes;
use App\Model\Users;
use Pop\Db\Sql\Migration\AbstractMigration;

class UserRoot extends AbstractMigration
{
	public function up(): void
    {
        // create roots for users that don't have one
        foreach (Users::findAll() as $user) {
            $root = $user->root();
            if (!is_null($root))
                continue;
            // have no root for this user
            $root = Inodes::New(Inodes::TYPE_COLLECTION, $user->idStr(), owner: $user->id);
            $root->save();
        }
	}

	public function down(): void
    {
	}

}