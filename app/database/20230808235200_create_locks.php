<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateLocks extends AbstractMigration
{
    public function up(): void
    {
        $schema = $this->db->createSchema();
        $schema->create('inode_locks')
            ->int('id')->increment()
            ->int('inode_id')
            ->varchar('token', 255)
            ->bigInt('created')
            ->bigInt('expires')
            ->tinyInt('depth')
            ->tinyInt('scope')
            ->varchar('owner', 255)
            ->primary('id')
            ->unique('token');
        $schema->execute();
    }

    public function down(): void
    {
        $schema = $this->db->createSchema();
        $schema->drop('inode_locks');
        $schema->execute();
    }
}