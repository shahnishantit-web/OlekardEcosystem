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
        Schema::connection('community_services')->create('community_services.event_poc_event_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->uuid('actor_id');
            $table->string('idempotency_key', 128);
            $table->timestampTz('simulated_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_poc_event_closures');
    }
};
