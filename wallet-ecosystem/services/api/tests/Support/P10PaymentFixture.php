<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Ramsey\Uuid\Uuid;

trait P10PaymentFixture
{
    private function paymentFixture(): array
    {
        $id = (string) Uuid::uuid7();
        $provider = PaymentProviderModel::create(['id' => (string) Uuid::uuid7(), 'code' => 'p10-'.substr($id, -12),
            'display_name' => 'TEST ONLY', 'adapter_identifier' => 'mock_bank', 'adapter_version' => 'test',
            'environment' => 'mock', 'is_enabled' => true, 'is_suspended' => false, 'sort_order' => 1]);
        $account = PaymentProviderAccountModel::create(['id' => (string) Uuid::uuid7(), 'provider_id' => $provider->id,
            'merchant_account_reference' => 'test-only', 'is_active' => true, 'supported_currencies' => ['BWP'],
            'min_amount_minor' => 0, 'max_amount_minor' => 1000000]);
        $intent = PaymentIntentModel::create(['id' => $id, 'reference' => 'P10-'.substr($id, -12),
            'purpose_type' => 'test_only', 'purpose_reference' => (string) Uuid::uuid7(),
            'organisation_id' => (string) Uuid::uuid7(), 'payer_actor_id' => (string) Uuid::uuid7(),
            'currency_code' => 'BWP', 'amount_minor' => 100, 'status' => 'pending',
            'idempotency_key' => $id, 'correlation_id' => (string) Uuid::uuid7(), 'expires_at' => now()->addHour()]);
        return [$intent, $provider, $account];
    }
}
