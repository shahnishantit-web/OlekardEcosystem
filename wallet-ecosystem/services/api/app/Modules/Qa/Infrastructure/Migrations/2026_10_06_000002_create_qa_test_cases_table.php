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
        Schema::connection('platform')->create('qa.test_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 120)->unique();
            $table->string('category', 60);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('scenario_type', 40);
            $table->jsonb('expected_outcome')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('updated_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_cases_category ON qa.test_cases (category)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_cases_active ON qa.test_cases (is_active) WHERE is_active = true'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('qa.test_cases');
    }
};
