<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateChunkedUploads extends AbstractMigration
{
	public function up()
	{
		$schema = $this->db->createSchema();
		$schema->create('chunked_uploads')
			->int('id', 16)->increment()
            ->varchar('transfer_id', 255)
            ->int('started', 16)
            ->int('num_parts', 16)->nullable()
            ->int('total_length', 16)->nullable()
			->primary('id')
            ->unique('transfer_id');

        $schema->create('chunked_upload_parts')
            ->int('id', 16)->increment()
            ->int('upload_id', 16)
            ->int('part', 16)
            ->int('size', 16)
            ->varchar('object', 255)
            ->primary('id')
            ->unique(['upload_id','part']);

        $schema->execute();
	}

	public function down()
	{
		$schema = $this->db->createSchema();
		$schema->drop('chunked_uploads');
		$schema->drop('chunked_upload_parts');
        $schema->execute();
	}
}