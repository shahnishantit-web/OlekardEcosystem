<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'platform';

    public function up(): void
    {
        // Laravel encrypted:array emits ciphertext, which cannot be stored as jsonb.
        DB::connection('platform')->statement('ALTER TABLE withdrawal.withdrawal_transactions ALTER COLUMN payout_destination TYPE text USING payout_destination::text');
        DB::connection('platform')->table('withdrawal.withdrawal_transactions')->orderBy('id')->each(function ($row): void {
            $decoded = json_decode($row->payout_destination, true);
            $encrypted = is_array($decoded)
                ? Crypt::encryptString(json_encode($decoded, JSON_THROW_ON_ERROR))
                : $decoded;
            if (is_string($encrypted)) {
                DB::connection('platform')->table('withdrawal.withdrawal_transactions')->where('id', $row->id)->update(['payout_destination' => $encrypted]);
            }
        });
    }

    public function down(): void
    {
        // Preserve ciphertext on rollback; never decrypt sensitive bank details into JSON.
        DB::connection('platform')->statement('ALTER TABLE withdrawal.withdrawal_transactions ALTER COLUMN payout_destination TYPE jsonb USING to_jsonb(payout_destination)');
    }
};
