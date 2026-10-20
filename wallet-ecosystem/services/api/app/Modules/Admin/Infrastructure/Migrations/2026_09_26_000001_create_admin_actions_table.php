<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'admin';

    public function up(): void
    {
        DB::connection('admin')->statement('CREATE SCHEMA IF NOT EXISTS admin');

        Schema::connection('admin')->create('admin.admin_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action_type', 50);
            $table->uuid('actor_id');
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            $table->jsonb('metadata')->default('{}');
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('performed_at')->useCurrent();
        });

        DB::connection('admin')->statement('
            CREATE INDEX idx_admin_actions_actor ON admin.admin_actions (actor_id, performed_at DESC)
        ');
        DB::connection('admin')->statement('
            CREATE INDEX idx_admin_actions_subject ON admin.admin_actions (subject_type, subject_id, performed_at DESC)
        ');
        DB::connection('admin')->statement('
            CREATE INDEX idx_admin_actions_type ON admin.admin_actions (action_type, performed_at DESC)
        ');
    }

    public function down(): void
    {
        Schema::connection('admin')->dropIfExists('admin.admin_actions');
    }
};
