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
        Schema::connection('identity')->create('identity.permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique()->notNullable();
            $table->string('display_name', 150)->notNullable();
            $table->text('description')->nullable();
            $table->string('module', 50)->notNullable();
            $table->boolean('is_system_permission')->default(false)->notNullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('identity')->statement(
            'CREATE INDEX identity_permissions_module_idx ON identity.permissions (module)'
        );
        DB::connection('identity')->statement(
            'CREATE INDEX identity_permissions_deleted_at_idx ON identity.permissions (deleted_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.permissions');
    }
};
