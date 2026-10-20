<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->create('identity.user_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->notNullable();
            $table->uuid('role_id')->notNullable();
            $table->uuid('created_by_actor_id')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();

            $table->unique(['user_id', 'role_id'], 'identity_user_roles_unique');
        });

        DB::connection('identity')->statement(
            'CREATE INDEX identity_user_roles_user_id_idx ON identity.user_roles (user_id)'
        );
        DB::connection('identity')->statement(
            'ALTER TABLE identity.user_roles
             ADD CONSTRAINT identity_user_roles_user_id_fk
             FOREIGN KEY (user_id) REFERENCES identity.users(id) ON DELETE CASCADE'
        );
        DB::connection('identity')->statement(
            'ALTER TABLE identity.user_roles
             ADD CONSTRAINT identity_user_roles_role_id_fk
             FOREIGN KEY (role_id) REFERENCES identity.roles(id) ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.user_roles');
    }
};
