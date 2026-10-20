<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.risk_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->string('rule_type', 50)->comment('RuleType enum');
            $table->string('applies_to', 50)->comment('global|customer|agent|wallet');
            $table->uuid('subject_id')->nullable()->index();
            $table->decimal('threshold_amount', 19, 4)->nullable();
            $table->integer('threshold_count')->nullable();
            $table->integer('window_seconds')->nullable();
            $table->decimal('risk_score_weight', 5, 2)->default(1.0);
            $table->string('action', 20)->comment('RiskDecisionEnum');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('effective_from')->useCurrent();
            $table->timestampTz('effective_until')->nullable();
            $table->uuid('created_by');
            $table->uuid('updated_by');
            $table->timestampsTz();

            $table->index(['rule_type', 'applies_to', 'is_active']);
            $table->index(['applies_to', 'subject_id']);
            $table->unique(['name', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.risk_rules');
    }
};
