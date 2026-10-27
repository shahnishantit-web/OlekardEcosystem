<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\EligibilityPipeline;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Contracts\AdapterAllowlistInterface;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Enums\EligibilityReason;
use App\Modules\Payment\Domain\ValueObjects\EligibilityResult;
use App\Modules\Payment\Http\Controllers\Admin\PaymentProviderAdminController;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class EligibilityPreviewTest extends TestCase
{
    /** @var PaymentProviderRepositoryInterface&MockObject */
    private PaymentProviderRepositoryInterface $repository;

    private EligibilityPipeline $pipeline;

    private PaymentProviderAdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $audit           = $this->createMock(AuditServiceInterface::class);
        $adminService    = new PaymentProviderAdminService($audit);
        $this->repository = $this->createMock(PaymentProviderRepositoryInterface::class);
        $allowlist       = $this->createMock(AdapterAllowlistInterface::class);
        $this->pipeline  = new EligibilityPipeline();

        $this->controller = new PaymentProviderAdminController(
            $adminService,
            $allowlist,
            $this->repository,
            $this->pipeline,
        );
    }

    private function makeAccount(string $code, int $version = 1): \App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel
    {
        $provider               = new \App\Modules\Payment\Infrastructure\Models\PaymentProviderModel();
        $provider->code         = $code;
        $provider->display_name = ucfirst($code);
        $provider->is_enabled = true;
        $provider->is_suspended = false;

        $account                  = new \App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel();
        $account->id              = "account-{$code}";
        $account->config_version  = $version;
        $account->is_active = true;
        $account->supported_currencies = ['BWP'];
        $account->min_amount_minor = 0;
        $account->max_amount_minor = 100000;
        $account->setRelation('provider', $provider);

        return $account;
    }

    public function test_preview_returns_annotated_list_with_all_accounts(): void
    {
        $accounts = [
            $this->makeAccount('olekard_wallet', 3),
            $this->makeAccount('mock_bank', 1),
        ];

        $this->repository->method('findAllWithProviders')->willReturn($accounts);
        $accounts[1]->provider->is_suspended = true;

        $request = Request::create('/eligibility-preview', 'GET', [
            'currency' => 'BWP', 'amount' => '5000', 'organisation_id' => 'org-1',
        ]);
        $request->setUserResolver(static fn() => (object) ['id' => 'admin-1']);

        $response = $this->controller->eligibilityPreview($request);
        $data     = json_decode($response->getContent(), true)['data'];

        self::assertCount(2, $data);
        self::assertTrue($data[0]['is_eligible']);
        self::assertNull($data[0]['ineligibility_reason']);
        self::assertFalse($data[1]['is_eligible']);
        self::assertSame('PROVIDER_SUSPENDED', $data[1]['ineligibility_reason']);
    }

    public function test_preview_includes_config_version_in_response(): void
    {
        $this->repository->method('findAllWithProviders')->willReturn([
            $this->makeAccount('olekard_wallet', 5),
        ]);

        $request = Request::create('/eligibility-preview', 'GET');
        $request->setUserResolver(static fn() => (object) ['id' => 'admin-1']);

        $response = $this->controller->eligibilityPreview($request);
        $data     = json_decode($response->getContent(), true)['data'];

        self::assertSame(5, $data[0]['config_version']);
    }

    public function test_preview_ineligibility_reason_is_string_value(): void
    {
        $account = $this->makeAccount('mock_bank', 1);
        $account->supported_currencies = ['USD'];
        $this->repository->method('findAllWithProviders')->willReturn([$account]);

        $request = Request::create('/eligibility-preview', 'GET');
        $request->setUserResolver(static fn() => (object) ['id' => 'admin-1']);

        $response = $this->controller->eligibilityPreview($request);
        $data     = json_decode($response->getContent(), true)['data'];

        self::assertSame('CURRENCY_NOT_SUPPORTED', $data[0]['ineligibility_reason']);
    }
}
