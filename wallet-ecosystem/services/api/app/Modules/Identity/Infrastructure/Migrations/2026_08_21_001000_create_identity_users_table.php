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
        Schema::connection('identity')->create('identity.users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('mobile_number', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('status', 20)->default('pending_verification');
            $table->uuid('default_role_id')->nullable();
            $table->string('actor_type', 20)->default('CUSTOMER');
            $table->string('lock_reason', 100)->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->integer('lockout_episode_count')->default(0);
            $table->integer('failed_attempt_count')->default(0);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        // Partial unique indexes (not expressible in Blueprint fluently)
        DB::connection('identity')->statement(
            'CREATE UNIQUE INDEX identity_users_mobile_number_unique ON identity.users (mobile_number) WHERE mobile_number IS NOT NULL'
        );
        DB::connection('identity')->statement(
            'CREATE UNIQUE INDEX identity_users_email_unique ON identity.users (email) WHERE email IS NOT NULL'
        );
        DB::connection('identity')->statement(
            'CREATE INDEX identity_users_status_idx ON identity.users (status)'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.users');
    }
};
