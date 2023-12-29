<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateThumbnails extends AbstractMigration
{
	public function up(): void
    {
		$schema = $this->db->createSchema();
		$schema->create('thumbnails')
            ->int('id')->increment()
            ->varchar('for_object', 255)
            ->tinyInt('width')
            ->tinyInt('height')
            ->varchar('content_type', 255)
            ->varchar('object', 255)
			->primary('id')
            ->index('for_object', 'thumbnails_by_target')
            ->index('width', 'thumbnails_by_width')
            ->index('height', 'thumbnails_by_height')
            ->index('object', 'thumbnails_by_object');

        $schema->execute();
	}

	public function down(): void
    {
		$schema = $this->db->createSchema();
		$schema->drop('thumbnails');
        $schema->execute();
	}
}