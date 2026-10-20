<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'notification';

    public function up(): void
    {
        Schema::connection('notification')->create('notification.notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->string('channel', 20);
            $table->string('status', 30)->default('queued');
            $table->smallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('provider_ref', 255)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->foreign('notification_id')
                ->references('id')
                ->on('notification.notifications')
                ->cascadeOnDelete();

            $table->unique(['notification_id', 'channel']);
            $table->index(['status', 'next_retry_at']);
            $table->index('notification_id');
        });
    }

    public function down(): void
    {
        Schema::connection('notification')->dropIfExists('notification.notification_deliveries');
    }
};
