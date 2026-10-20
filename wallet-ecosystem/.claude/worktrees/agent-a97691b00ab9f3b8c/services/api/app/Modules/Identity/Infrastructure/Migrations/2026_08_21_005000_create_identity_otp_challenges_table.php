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
        Schema::connection('identity')->create('identity.otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();          // NULL for pre-registration challenges
            $table->string('challenge_type', 30);
            $table->string('identifier', 255);            // Mobile/email (raw; masked in logs/events)
            $table->string('otp_hash', 64);               // SHA-256 hex — NEVER plaintext OTP
            $table->string('status', 20)->default('pending');
            $table->integer('attempt_count')->default(0);
            $table->integer('resend_count')->default(0);
            $table->timestampTz('last_resent_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['identifier', 'challenge_type', 'status']);
            $table->index(['status', 'expires_at']);
        });

        // Partial index for user_id (only when not null)
        DB::connection('identity')->statement(
            'CREATE INDEX identity_otp_challenges_user_id_idx ON identity.otp_challenges (user_id) WHERE user_id IS NOT NULL'
        );

        // CHECK constraint: user_id may only be NULL for registration challenges
        DB::connection('identity')->statement(
            "ALTER TABLE identity.otp_challenges ADD CONSTRAINT otp_challenges_user_id_check CHECK (user_id IS NOT NULL OR challenge_type = 'registration')"
        );

        // FK only applies when user_id is not null — deferred constraint
        DB::connection('identity')->statement(
            'ALTER TABLE identity.otp_challenges ADD CONSTRAINT identity_otp_challenges_user_id_fk FOREIGN KEY (user_id) REFERENCES identity.users(id) NOT VALID'
        );
        DB::connection('identity')->statement(
            'ALTER TABLE identity.otp_challenges VALIDATE CONSTRAINT identity_otp_challenges_user_id_fk'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.otp_challenges');
    }
};
