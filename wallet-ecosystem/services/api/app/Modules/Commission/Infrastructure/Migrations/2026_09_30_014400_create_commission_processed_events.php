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
        Schema::connection('platform')->create('commission.processed_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id', 255);
            $table->string('consumer_class', 255);
            $table->timestampTz('processed_at')->useCurrent();
        });

        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_commission_processed_events
             ON commission.processed_events (event_id, consumer_class)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('commission.processed_events');
    }
};
