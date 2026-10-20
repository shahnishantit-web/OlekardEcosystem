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
        Schema::connection('platform')->create('integration.integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_type', 32);
            $table->string('provider_slug', 64);
            $table->string('display_name', 255);
            $table->string('credentials_reference', 255);
            $table->string('endpoint_override', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_default')->default(false);
            $table->jsonb('configuration')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'ALTER TABLE integration.integrations ADD CONSTRAINT uq_integrations_type_slug UNIQUE (provider_type, provider_slug)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX idx_integrations_type_status ON integration.integrations (provider_type, status)'
        );

        // Uniqueness of is_default per provider_type is enforced at application layer (demote previous default).
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('integration.integrations');
    }
};
