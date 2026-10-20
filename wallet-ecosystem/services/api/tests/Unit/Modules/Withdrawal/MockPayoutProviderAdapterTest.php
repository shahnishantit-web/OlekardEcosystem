<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Withdrawal\Infrastructure\Adapters\MockPayoutProviderAdapter;
use PHPUnit\Framework\TestCase;

final class MockPayoutProviderAdapterTest extends TestCase
{
    private MockPayoutProviderAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MockPayoutProviderAdapter();
    }

    /** @test */
    public function initiate_returns_initiated_status_with_provider_reference(): void
    {
        $result = $this->adapter->initiate(['id' => 'tx-abc-123']);

        $this->assertSame('initiated', $result['status']);
        $this->assertSame('mock-payout-tx-abc-123', $result['provider_reference_id']);
        $this->assertArrayHasKey('raw_response', $result);
    }

    /** @test */
    public function verify_callback_returns_valid_true_for_mock_signature(): void
    {
        $result = $this->adapter->verifyCallback(
            ['status' => 'success', 'provider_reference_id' => 'mock-ref-1'],
            'mock-valid-sig',
            'mock'
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('success', $result['status']);
    }

    /** @test */
    public function verify_callback_returns_valid_false_for_wrong_signature(): void
    {
        $result = $this->adapter->verifyCallback(
            ['status' => 'success', 'provider_reference_id' => 'mock-ref-1'],
            'invalid-signature',
            'mock'
        );

        $this->assertFalse($result['valid']);
    }

    /** @test */
    public function verify_callback_passes_through_status_from_payload(): void
    {
        $result = $this->adapter->verifyCallback(
            ['status' => 'failed', 'provider_reference_id' => 'mock-ref-2'],
            'mock-valid-sig',
            'mock'
        );

        $this->assertSame('failed', $result['status']);
    }
}
