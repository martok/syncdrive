<?php

use Pop\Db\Sql\Migration\AbstractMigration;

class CreateAppAuth extends AbstractMigration
{
    public function up(): void
    {
        $schema = $this->db->createSchema();
        $schema->create('login_tokens')
            ->int('id')->increment()
            ->bigInt('created')
            ->varchar('user_agent', 255)
            ->varchar('poll_token', 255)
            ->varchar('login_token', 255)
            ->varchar('login_name', 255)->nullable()
            ->varchar('app_password', 255)->nullable()
            ->primary('id')
            ->unique('login_token')
            ->unique('poll_token');

        $schema->create('app_passwords')
            ->int('id')->increment()
            ->bigInt('created')
            ->bigInt('last_used')->nullable()
            ->varchar('user_agent', 255)
            ->varchar('login_name', 255)
            ->varchar('password', 255)
            ->int('user_id')
            ->primary('id')
            ->unique(['login_name', 'password']);

        $schema->execute();
    }

    public function down(): void
    {
        $schema = $this->db->createSchema();
        $schema->drop('login_tokens');
        $schema->drop('app_passwords');
        $schema->execute();
    }
}