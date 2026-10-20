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
        Schema::connection('identity')->create('identity.role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('role_id')->notNullable();
            $table->uuid('permission_id')->notNullable();
            $table->uuid('created_by_actor_id')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();

            $table->unique(['role_id', 'permission_id'], 'identity_role_permissions_unique');
        });

        DB::connection('identity')->statement(
            'CREATE INDEX identity_role_permissions_role_id_idx ON identity.role_permissions (role_id)'
        );
        DB::connection('identity')->statement(
            'CREATE INDEX identity_role_permissions_permission_id_idx ON identity.role_permissions (permission_id)'
        );
        DB::connection('identity')->statement(
            'ALTER TABLE identity.role_permissions
             ADD CONSTRAINT identity_role_permissions_role_id_fk
             FOREIGN KEY (role_id) REFERENCES identity.roles(id) ON DELETE CASCADE'
        );
        DB::connection('identity')->statement(
            'ALTER TABLE identity.role_permissions
             ADD CONSTRAINT identity_role_permissions_permission_id_fk
             FOREIGN KEY (permission_id) REFERENCES identity.permissions(id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.role_permissions');
    }
};
