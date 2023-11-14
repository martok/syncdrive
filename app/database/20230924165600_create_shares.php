<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateShares extends AbstractMigration
{
	public function up(): void
    {
		$schema = $this->db->createSchema();
        $schema->create('inode_shares')
            ->int('id', 16)->increment()
            ->int('inode_id', 16)
            ->int('sharer_id', 16)->nullable()
            ->int('modified', 16)
            ->varchar('permissions', 255)
            ->varchar('token', 255)->nullable()
            ->varchar('password', 255)->nullable()
            ->varchar('presentation', 255)->nullable()
            ->primary('id')
            ->index('token', 'share_by_token');
        $schema->execute();
	}

	public function down(): void
    {
		$schema = $this->db->createSchema();
		$schema->drop('shared_inodes');
		$schema->drop('external_shares');
        $schema->execute();
	}
}