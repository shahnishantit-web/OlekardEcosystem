<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds last_completed_*_challenge_id columns so CHALLENGE_ALREADY_COMPLETED can be distinguished
 * from CHALLENGE_NOT_FOUND when a user retries verification with a new idempotency key after
 * the challenge was already consumed (spec §10.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('profile')->table('profile.user_profiles', static function (Blueprint $table): void {
            $table->uuid('last_completed_email_challenge_id')->nullable()->after('pending_email_challenge_id');
            $table->uuid('last_completed_mobile_challenge_id')->nullable()->after('pending_mobile_challenge_id');
        });
    }

    public function down(): void
    {
        Schema::connection('profile')->table('profile.user_profiles', static function (Blueprint $table): void {
            $table->dropColumn(['last_completed_email_challenge_id', 'last_completed_mobile_challenge_id']);
        });
    }
};
