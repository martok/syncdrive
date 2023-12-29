<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateUsers extends AbstractMigration
{
	public function up(): void
    {
		$schema = $this->db->createSchema();
		$schema->create('users')
			->int('id')->increment()
			->varchar('username', 255)
			->varchar('password', 255)
			->primary('id');

        $schema->execute();
	}

	public function down(): void
    {
		$schema = $this->db->createSchema();
		$schema->drop('users');
        $schema->execute();
	}
}