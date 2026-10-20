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
        Schema::connection('platform')->create('audit.compliance_status', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->smallInteger('kyc_tier')->default(0);
            $table->string('aml_risk_level', 16)->default('low');
            $table->jsonb('active_restrictions')->default('[]');
            $table->timestampTz('last_changed_at')->nullable();
            $table->uuid('last_audit_event_id')->nullable();
            $table->integer('version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('aml_risk_level');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('audit.compliance_status');
    }
};
