<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('reporting.report_definitions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 64)->unique();
            $table->string('name', 128);
            $table->text('description');
            $table->string('required_permission', 128);
            $table->boolean('pii_sensitive')->default(false);
            $table->jsonb('filter_schema');
            $table->integer('default_max_rows')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('reporting.report_definitions');
    }
};
