<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

/** Legacy URL guards; signed acceptance is covered by P10WebhookIngressTest. */
final class CallbackProviderValidationTest extends IntegrationTestCase
{
    public function test_unknown_provider_mapping_returns_404_without_dispatch(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/payment/callbacks/not-configured-'.Uuid::uuid7(), ['event' => 'test'])->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_ambiguous_account_mapping_returns_404_without_dispatch(): void
    {
        Queue::fake();
        $code = 'test-'.substr((string) Uuid::uuid7(), -12);
        foreach ([1, 2] as $index) {
            IntegrationModel::create(['id' => (string) Uuid::uuid7(), 'provider_type' => 'payment',
                'provider_slug' => $code.'-'.$index, 'display_name' => 'TEST ONLY',
                'status' => 'active', 'is_default' => false, 'credentials_reference' => 'TEST_ONLY',
                'configuration' => ['payment_provider_code' => $code]]);
        }
        $this->postJson('/api/v1/payment/callbacks/'.$code, ['event' => 'test'])->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_empty_body_is_rejected_before_ingress(): void
    {
        Queue::fake();
        $this->call('POST', '/api/v1/payment/callbacks/test-only', [], [], [], ['HTTP_ACCEPT' => 'application/json'], '')->assertStatus(400);
        Queue::assertNothingPushed();
    }
}
