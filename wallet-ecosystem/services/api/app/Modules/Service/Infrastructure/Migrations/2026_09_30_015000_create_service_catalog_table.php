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
        DB::connection('platform')->statement('CREATE SCHEMA IF NOT EXISTS service');

        Schema::connection('platform')->create('service.services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255)->notNullable();
            $table->string('category', 100)->notNullable();
            $table->text('description')->nullable();
            $table->jsonb('required_fields')->notNullable()->default('[]');
            $table->string('fee_type', 20)->notNullable();
            $table->bigInteger('fee_value')->notNullable()->default(0);
            $table->bigInteger('min_amount')->notNullable();
            $table->bigInteger('max_amount')->notNullable();
            $table->char('currency_code', 10)->notNullable()->default('BWP');
            $table->string('provider_code', 100)->notNullable();
            $table->string('status', 20)->notNullable()->default('active');
            $table->uuid('created_by')->notNullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE service.services
             ADD CONSTRAINT services_fee_type_check CHECK (fee_type IN ('flat','percentage'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE service.services
             ADD CONSTRAINT services_status_check CHECK (status IN ('active','inactive'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE service.services
             ADD CONSTRAINT services_fee_value_check CHECK (fee_value >= 0)"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE service.services
             ADD CONSTRAINT services_min_amount_check CHECK (min_amount > 0)"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE service.services
             ADD CONSTRAINT services_max_amount_check CHECK (max_amount >= min_amount)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX services_status_category_idx ON service.services (status, category)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX services_provider_code_idx ON service.services (provider_code)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('service.services');
    }
};
