<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'profile';

    public function up(): void
    {
        Schema::connection('profile')->create('profile.account_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->uuid('actor_id');
            $table->string('actor_type', 20);
            $table->jsonb('closure_obligations')->nullable();
            $table->uuid('correlation_id');
            $table->timestampTz('created_at')->useCurrent();
            // No updated_at — this table is INSERT-only (append-only audit trail)
        });

        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_status_history_user_id ON profile.account_status_history (user_id)'
        );
        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_status_history_user_created ON profile.account_status_history (user_id, created_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('profile')->dropIfExists('profile.account_status_history');
    }
};
