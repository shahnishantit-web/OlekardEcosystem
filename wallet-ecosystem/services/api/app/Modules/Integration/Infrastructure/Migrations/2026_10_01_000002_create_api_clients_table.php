<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('integration.api_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('api_key', 64)->unique();
            $table->string('secret_hash', 255);
            $table->string('status', 16)->default('active');
            $table->jsonb('permissions')->default('[]');
            $table->integer('rate_limit_per_minute')->default(60);
            $table->timestampTz('last_used_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_api_clients_status ON integration.api_clients (status)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('integration.api_clients');
    }
};
