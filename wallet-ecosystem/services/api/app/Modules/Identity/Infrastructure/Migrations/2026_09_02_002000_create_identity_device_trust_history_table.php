<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->create('device_trust_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->index();
            $table->uuid('user_id')->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->uuid('actor_id');
            $table->string('actor_type', 20);
            $table->text('reason')->nullable();
            $table->uuid('correlation_id');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::connection('identity')->statement(
            'CREATE INDEX idx_device_trust_history_device_created ON identity.device_trust_history (device_id, created_at DESC)'
        );
        DB::connection('identity')->statement(
            'CREATE INDEX idx_device_trust_history_user_created ON identity.device_trust_history (user_id, created_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('device_trust_history');
    }
};
