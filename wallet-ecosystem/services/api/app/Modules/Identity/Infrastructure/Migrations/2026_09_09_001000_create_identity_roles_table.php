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
        Schema::connection('identity')->create('identity.roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 50)->unique()->notNullable();
            $table->string('display_name', 100)->notNullable();
            $table->text('description')->nullable();
            $table->boolean('is_system_role')->default(false)->notNullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('identity')->statement(
            'CREATE INDEX identity_roles_deleted_at_idx ON identity.roles (deleted_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.roles');
    }
};
