<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.risk_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('alert_id')->index();
            $table->string('decision', 50);
            $table->text('rationale');
            $table->uuid('decided_by');
            $table->timestampTz('decided_at');
            $table->uuid('maker_id')->nullable();
            $table->uuid('checker_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('alert_id')->references('id')->on('risk.risk_alerts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.risk_decisions');
    }
};
