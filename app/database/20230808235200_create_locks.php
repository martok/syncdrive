<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateLocks extends AbstractMigration
{
	public function up(): void
    {
		$schema = $this->db->createSchema();
        $schema->create('inode_locks')
            ->int('id', 16)->increment()
            ->int('inode_id', 16)
            ->varchar('token', 255)
            ->int('created', 16)
            ->int('expires', 16)
            ->int('depth', 8)
            ->int('scope', 8)
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