<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateChunkedUploads extends AbstractMigration
{
    public function up(): void
    {
        $schema = $this->db->createSchema();
        $schema->create('chunked_uploads')
            ->int('id')->increment()
            ->varchar('transfer_id', 255)
            ->bigInt('started')
            ->int('num_parts')->nullable()
            ->bigInt('total_length')->nullable()
            ->primary('id')
            ->unique('transfer_id');

        $schema->create('chunked_upload_parts')
            ->int('id')->increment()
            ->int('upload_id')
            ->varchar('part', 255)
            ->bigInt('size')
            ->varchar('object', 255)
            ->primary('id')
            ->unique(['upload_id','part']);

        $schema->execute();
    }

    public function down(): void
    {
        $schema = $this->db->createSchema();
        $schema->drop('chunked_uploads');
        $schema->drop('chunked_upload_parts');
        $schema->execute();
    }
}