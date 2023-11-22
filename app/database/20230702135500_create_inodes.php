<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateInodes extends AbstractMigration
{
	public function up(): void
    {
		$schema = $this->db->createSchema();
		$schema->create('inodes')
			->int('id', 16)->increment()
            ->int('parent_id', 16)->nullable()
            ->int('owner_id', 16)->nullable()
            ->int('type', 8)
			->varchar('name', 255)
            ->int('deleted', 16)->nullable()
            ->int('modified', 16)
            ->varchar('etag', 255)->nullable()
            ->int('current_version_id', 16)->nullable()
            ->int('link_target', 16)->nullable()
			->primary('id')
            ->index(['parent_id', 'name'], 'subfolder');

        $schema->create('file_versions')
            ->int('id', 16)->increment()
            ->int('inode_id', 16)
            ->int('created', 16)
            ->int('creator_id', 16)->nullable()
            ->int('size', 16)
            ->varchar('object', 255)
            ->varchar('name', 255)->nullable()
            ->varchar('hashes', 255)->nullable()
            ->primary('id')
            ->index('inode_id', 'versions_by_inode')
            ->index('object', 'versions_by_object');

        $schema->create('inode_props')
            ->int('id', 16)->increment()
            ->int('inode_id', 16)
            ->varchar('name', 255)
            ->int('type', 8)
            ->text('value')
            ->primary('id')
            ->unique(['inode_id', 'name'], 'props_for_inode');

        $schema->execute();
	}

	public function down(): void
    {
		$schema = $this->db->createSchema();
		$schema->drop('inodes');
		$schema->drop('file_versions');
		$schema->drop('inode_props');
        $schema->execute();
	}
}