<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Exceptions\AdapterNotInAllowlistException;
use App\Modules\Payment\Infrastructure\Config\AdapterAllowlistConfig;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class AdapterAllowlistTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;
    protected $connectionsToTransact = ['platform', 'payment'];
    /** @var ConfigurationServiceInterface&MockObject */
    private ConfigurationServiceInterface $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = $this->createMock(ConfigurationServiceInterface::class);
    }

    public function test_known_adapter_is_allowed(): void
    {
        $this->config->method('getOrDefault')->willReturn('["olekard_wallet","mock_bank"]');
        $allowlist = new AdapterAllowlistConfig($this->config);

        self::assertTrue($allowlist->isAllowed('olekard_wallet'));
        self::assertTrue($allowlist->isAllowed('mock_bank'));
    }

    public function test_unknown_adapter_is_rejected(): void
    {
        $this->config->method('getOrDefault')->willReturn('["olekard_wallet","mock_bank"]');
        $allowlist = new AdapterAllowlistConfig($this->config);

        self::assertFalse($allowlist->isAllowed('rogue_gateway'));
    }

    public function test_get_allowed_identifiers_returns_list(): void
    {
        $this->config->method('getOrDefault')->willReturn('["olekard_wallet","mock_bank"]');
        $allowlist = new AdapterAllowlistConfig($this->config);

        self::assertSame(['olekard_wallet', 'mock_bank'], $allowlist->getAllowedIdentifiers());
    }

    public function test_fallback_used_when_config_key_missing(): void
    {
        $this->config->method('getOrDefault')->willReturn(null);
        $allowlist = new AdapterAllowlistConfig($this->config);

        self::assertTrue($allowlist->isAllowed('olekard_wallet'));
        self::assertTrue($allowlist->isAllowed('mock_bank'));
    }

    public function test_explicit_empty_array_config_denies_all_adapters(): void
    {
        $this->config->method('getOrDefault')->willReturn('[]');
        $allowlist = new AdapterAllowlistConfig($this->config);

        self::assertFalse($allowlist->isAllowed('olekard_wallet'));
        self::assertSame([], $allowlist->getAllowedIdentifiers());
    }

    public function test_create_provider_throws_when_adapter_not_in_allowlist(): void
    {
        $this->config->method('getOrDefault')->willReturn('["olekard_wallet"]');
        $allowlist = new AdapterAllowlistConfig($this->config);

        $auditMock = $this->createMock(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class);
        $service   = new PaymentProviderAdminService($auditMock, $allowlist);

        $this->expectException(AdapterNotInAllowlistException::class);
        $this->expectExceptionMessage("'unknown_bank'");

        $service->createProvider([
            'code'               => 'new_bank',
            'display_name'       => 'New Bank',
            'adapter_identifier' => 'unknown_bank',
            'environment'        => 'sandbox',
        ], 'actor-1', 'corr-1');
    }

    public function test_create_provider_succeeds_with_allowed_adapter(): void
    {
        $this->config->method('getOrDefault')->willReturn('["olekard_wallet","mock_bank"]');
        $allowlist = new AdapterAllowlistConfig($this->config);

        $auditMock = $this->createMock(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class);

        $service = new PaymentProviderAdminService($auditMock, $allowlist);
        $provider = $service->createProvider([
                'code'               => 'test-'.\Ramsey\Uuid\Uuid::uuid7(),
                'display_name'       => 'Test',
                'adapter_identifier' => 'mock_bank',
                'environment'        => 'mock',
            ], 'actor-1', 'corr-1');
        self::assertFalse($provider->is_enabled);
        self::assertTrue($provider->exists);
    }
}
