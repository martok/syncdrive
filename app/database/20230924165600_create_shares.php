<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateShares extends AbstractMigration
{
	public function up(): void
    {
		$schema = $this->db->createSchema();
        $schema->create('inode_shares')
            ->int('id')->increment()
            ->int('inode_id')
            ->int('sharer_id')->nullable()
            ->bigInt('modified')
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