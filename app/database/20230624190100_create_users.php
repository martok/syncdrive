<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateUsers extends AbstractMigration
{
	public function up()
	{
		$schema = $this->db->createSchema();
		$schema->create('users')
			->int('id', 16)->increment()
			->varchar('username', 255)
			->varchar('password', 255)
			->primary('id');

        $schema->execute();
	}

	public function down()
	{
		$schema = $this->db->createSchema();
		$schema->drop('users');
        $schema->execute();
	}
}