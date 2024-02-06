<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateInodes extends AbstractMigration
{
    public function up(): void
    {
        $schema = $this->db->createSchema();
        $schema->create('inodes')
            ->int('id')->increment()
            ->int('parent_id')->nullable()
            ->int('owner_id')->nullable()
            ->tinyInt('type')
            ->varchar('name', 255)
            ->bigInt('deleted')->nullable()
            ->bigInt('modified')
            ->bigInt('size')->defaultIs(0)
            ->varchar('etag', 255)->nullable()
            ->int('current_version_id')->nullable()
            ->int('link_target')->nullable()
            ->primary('id')
            ->index(['parent_id', 'name'], 'subfolder');

        $schema->create('file_versions')
            ->int('id')->increment()
            ->int('inode_id')
            ->bigInt('created')
            ->int('creator_id')->nullable()
            ->bigInt('size')
            ->varchar('object', 255)
            ->varchar('name', 255)->nullable()
            ->varchar('hashes', 255)->nullable()
            ->primary('id')
            ->index('inode_id', 'versions_by_inode')
            ->index('object', 'versions_by_object');

        $schema->create('inode_props')
            ->int('id')->increment()
            ->int('inode_id')
            ->varchar('name', 255)
            ->tinyInt('type')
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