<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E26 T024 — Convert kyc.kyc_personal_info.date_of_birth from date to text
 * so the Eloquent `encrypted` cast can store AES-256-CBC ciphertext.
 * Existing rows will be re-encrypted by E26SecurityDataMigration (T026).
 */
return new class extends Migration
{
    protected $connection = 'kyc';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            "ALTER TABLE kyc.kyc_personal_info ALTER COLUMN date_of_birth TYPE text USING date_of_birth::text"
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            "ALTER TABLE kyc.kyc_personal_info ALTER COLUMN date_of_birth TYPE date USING date_of_birth::date"
        );
    }
};
