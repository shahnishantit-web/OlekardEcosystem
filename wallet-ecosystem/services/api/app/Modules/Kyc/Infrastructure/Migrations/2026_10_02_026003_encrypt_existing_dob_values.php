<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

/**
 * E26 T026 — Re-encrypt existing plaintext date_of_birth values after the column
 * type was converted from `date` to `text` in migration 026001.
 * Reads each row, encrypts the value with Laravel Encrypter, and saves it back.
 * Idempotent: rows that already contain valid ciphertext are skipped.
 */
return new class extends Migration
{
    protected $connection = 'kyc';

    public function up(): void
    {
        $rows = DB::connection($this->connection)
            ->table('kyc.kyc_personal_info')
            ->whereNotNull('date_of_birth')
            ->get(['id', 'date_of_birth']);

        foreach ($rows as $row) {
            // Skip rows that are already encrypted (Crypt::encrypt produces a base64-encoded
            // JSON payload starting with 'ey'). This makes the migration idempotent on re-run.
            if (str_starts_with((string) $row->date_of_birth, 'ey')) {
                continue;
            }

            DB::connection($this->connection)
                ->table('kyc.kyc_personal_info')
                ->where('id', $row->id)
                ->update(['date_of_birth' => Crypt::encrypt($row->date_of_birth)]);
        }
    }

    public function down(): void
    {
        // Decrypt back to plaintext on rollback
        $rows = DB::connection($this->connection)
            ->table('kyc.kyc_personal_info')
            ->whereNotNull('date_of_birth')
            ->get(['id', 'date_of_birth']);

        foreach ($rows as $row) {
            if (!str_starts_with((string) $row->date_of_birth, 'ey')) {
                continue;
            }

            try {
                $plain = Crypt::decrypt($row->date_of_birth);
                DB::connection($this->connection)
                    ->table('kyc.kyc_personal_info')
                    ->where('id', $row->id)
                    ->update(['date_of_birth' => $plain]);
            } catch (\Throwable) {
                // Skip rows that fail decryption during rollback
            }
        }
    }
};
