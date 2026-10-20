<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * T104: Seeds BankProviderDocumentationGap records for Bank1Slot, Bank2Slot, Bank3Slot.
 * These track what documentation is still needed before live bank integration can proceed.
 */
final class BankProviderDocumentationGapSeeder extends Seeder
{
    /** @var string[] */
    private const REQUIRED_ITEMS = [
        'base_url',
        'auth_scheme',
        'webhook_signature_algorithm',
        'webhook_signature_header',
        'supported_currencies',
        'refund_api_endpoint',
        'refund_parameters',
        'realtime_confirmation_behaviour',
        'settlement_timing',
        'sandbox_access_details',
    ];

    /** @var array<string, string> */
    private const BANK_SLOTS = [
        'Bank1Slot' => 'bank1',
        'Bank2Slot' => 'bank2',
        'Bank3Slot' => 'bank3',
    ];

    public function run(): void
    {
        foreach (self::BANK_SLOTS as $slotName => $providerCode) {
            $existingCount = DB::connection('payment')
                ->table('payment.bank_provider_documentation_gaps')
                ->where('provider_code', $providerCode)
                ->count();

            if ($existingCount > 0) {
                continue;
            }

            DB::connection('payment')->table('payment.bank_provider_documentation_gaps')->insert([
                'id'             => (string) Uuid::uuid7(),
                'provider_code'  => $providerCode,
                'slot_name'      => $slotName,
                'required_items' => json_encode(self::REQUIRED_ITEMS),
                'status'         => 'blocked',
                'notes'          => 'Live integration BLOCKED. Provide required documentation items before enabling.',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }
}
