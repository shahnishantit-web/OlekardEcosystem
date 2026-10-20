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
        Schema::connection('notification')->create('notification.notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_event_id');
            $table->string('source_event_type', 100);
            $table->string('notification_type', 100);
            $table->string('category', 20);
            $table->boolean('mandatory')->default(false);
            $table->uuid('recipient_id');
            $table->text('subject');
            $table->text('body');
            $table->string('status', 30)->default('queued');
            $table->timestamp('read_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->unique(['source_event_id', 'notification_type']);
            $table->index(['recipient_id', 'created_at']);
            $table->index(['recipient_id', 'read_at']);
            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::connection('notification')->dropIfExists('notification.notifications');
    }
};
