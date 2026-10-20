<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection('identity')->statement(
            'ALTER TABLE identity.users
             ADD CONSTRAINT identity_users_default_role_id_fk
             FOREIGN KEY (default_role_id)
             REFERENCES identity.roles(id)
             ON DELETE SET NULL
             DEFERRABLE INITIALLY DEFERRED'
        );
    }

    public function down(): void
    {
        DB::connection('identity')->statement(
            'ALTER TABLE identity.users DROP CONSTRAINT IF EXISTS identity_users_default_role_id_fk'
        );
    }
};
