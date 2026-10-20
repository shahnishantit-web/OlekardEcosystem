<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.risk_alert_events', function (Blueprint $table): void {
            $table->uuid('alert_id');
            $table->uuid('event_id');
            $table->timestampTz('linked_at')->useCurrent();

            $table->primary(['alert_id', 'event_id']);
            $table->foreign('alert_id')->references('id')->on('risk.risk_alerts')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('risk.risk_events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.risk_alert_events');
    }
};
