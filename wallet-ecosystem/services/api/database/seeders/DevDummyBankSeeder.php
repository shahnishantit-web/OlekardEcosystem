<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Display-only development bank placeholders; no accounts or live configuration. */
final class DevDummyBankSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'development', 'testing')) {
            $this->command?->warn('Development dummy banks are not seeded in this environment.');
            return;
        }

        DB::connection('payment')->transaction(function (): void {
            foreach ([1, 2, 3] as $number) {
                // Preserve existing configuration when this seed is rerun.
                PaymentProviderModel::firstOrCreate(['code' => 'bank'.$number], [
                    'id' => (string) Str::uuid7(), 'display_name' => 'Bank'.$number,
                    'adapter_identifier' => 'mock_bank', 'adapter_version' => '1.0.0',
                    'environment' => 'mock', 'is_enabled' => false, 'is_suspended' => false,
                    'is_default' => false, 'sort_order' => 100 + $number,
                    'bank_lifecycle_status' => 'draft', 'supported_countries' => [],
                    'description' => 'Development dummy bank — test only. No live connection or certification.',
                    'metadata_version' => 1,
                ]);
            }
        });
    }
}
