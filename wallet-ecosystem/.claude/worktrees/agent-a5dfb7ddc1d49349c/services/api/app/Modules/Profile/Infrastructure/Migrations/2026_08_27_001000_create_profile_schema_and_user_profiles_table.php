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
        DB::connection('profile')->statement('CREATE SCHEMA IF NOT EXISTS profile');

        Schema::connection('profile')->create('profile.user_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('address_street', 255)->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('address_region', 100)->nullable();
            $table->string('address_postal_code', 20)->nullable();
            $table->char('address_country', 2)->nullable();
            $table->char('preferred_language', 5)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('mobile_number', 20)->nullable();
            $table->string('profile_photo_key', 500)->nullable();
            $table->string('account_status', 30)->default('active');
            $table->string('pending_email', 255)->nullable();
            $table->uuid('pending_email_challenge_id')->nullable();
            $table->string('pending_mobile', 20)->nullable();
            $table->uuid('pending_mobile_challenge_id')->nullable();
            $table->timestampsTz();
        });

        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_user_profiles_account_status ON profile.user_profiles (account_status)'
        );
        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_user_profiles_email ON profile.user_profiles (email)'
        );
        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_user_profiles_mobile ON profile.user_profiles (mobile_number)'
        );
    }

    public function down(): void
    {
        Schema::connection('profile')->dropIfExists('profile.user_profiles');
        DB::connection('profile')->statement('DROP SCHEMA IF EXISTS profile CASCADE');
    }
};
