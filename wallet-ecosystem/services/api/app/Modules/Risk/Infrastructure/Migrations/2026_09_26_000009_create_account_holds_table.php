<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.account_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 30);
            $table->uuid('subject_id')->index();
            $table->uuid('wallet_id')->nullable();
            $table->string('hold_type', 50);
            $table->string('status', 20)->default('active');
            $table->text('reason');
            $table->uuid('alert_id')->nullable();
            $table->uuid('decision_id')->nullable();
            $table->uuid('placed_by');
            $table->uuid('lifted_by')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('lifted_at')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestampsTz();

            $table->index(['subject_id', 'status']);
        });

        DB::statement(
            'CREATE INDEX risk_account_holds_expiry ON risk.account_holds (status, expires_at) WHERE expires_at IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.account_holds');
    }
};
