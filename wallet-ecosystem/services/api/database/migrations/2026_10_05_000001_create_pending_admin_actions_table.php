<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_admin_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action_type', 100);
            $table->string('resource_type', 100);
            $table->string('resource_id', 36);
            $table->json('payload')->nullable();
            $table->string('maker_id', 36);
            $table->string('checker_id', 36)->nullable();
            $table->enum('status', ['pending_approval', 'approved', 'rejected', 'expired'])
                ->default('pending_approval');
            $table->string('idempotency_key', 255)->unique();
            $table->string('correlation_id', 36);
            $table->string('rejection_reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['action_type', 'resource_id']);
            $table->index(['maker_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_admin_actions');
    }
};
