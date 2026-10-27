<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\EligibilityPipeline;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Enums\EligibilityReason;
use App\Modules\Payment\Domain\Exceptions\UnknownCapabilityFlagException;
use App\Modules\Payment\Domain\Exceptions\UnknownInstrumentTypeException;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use PHPUnit\Framework\TestCase;

final class CapabilityValidationTest extends TestCase
{
    private PaymentProviderAdminService $service;
    private EligibilityPipeline         $pipeline;

    protected function setUp(): void
    {
        $audit         = $this->createMock(AuditServiceInterface::class);
        $this->service = new PaymentProviderAdminService($audit);
        $this->pipeline = new EligibilityPipeline();
    }

    public function test_unknown_capability_flag_key_throws(): void
    {
        $this->expectException(UnknownCapabilityFlagException::class);
        $this->service->validateCapabilityFlags(['supports_teleportation' => true]);
    }

    public function test_known_capability_flag_keys_accepted(): void
    {
        $this->service->validateCapabilityFlags([
            'supports_refund'  => true,
            'supports_webhook' => true,
            'supports_step_up' => false,
            'supports_qr'      => false,
            'supports_escrow'  => false,
        ]);

        self::assertTrue(true); // no exception
    }

    public function test_unknown_instrument_type_throws(): void
    {
        $this->expectException(UnknownInstrumentTypeException::class);
        $this->service->validateInstrumentTypes(['wallet_transfer', 'quantum_payment']);
    }

    public function test_known_instrument_types_accepted(): void
    {
        $this->service->validateInstrumentTypes([
            'wallet_transfer', 'bank_transfer', 'card_payment', 'qr_payment', 'mobile_money',
        ]);

        self::assertTrue(true); // no exception
    }

    public function test_pipeline_stage_8_excludes_account_without_required_capability(): void
    {
        $provider              = new PaymentProviderModel();
        $provider->is_enabled  = true;
        $provider->is_suspended = false;

        $account                         = new PaymentProviderAccountModel();
        $account->is_active              = true;
        $account->enabled_organisations  = [];
        $account->enabled_modules        = [];
        $account->enabled_purpose_types  = [];
        $account->country_restrictions   = [];
        $account->supported_currencies   = ['BWP'];
        $account->min_amount_minor       = 100;
        $account->max_amount_minor       = 10_000_000;
        $account->capability_flags       = ['supports_qr' => false];
        $account->setRelation('provider', $provider);

        $query = new OptionsQuery(
            purposeType: 'bill', purposeReference: 'ref', currencyCode: 'BWP',
            amountMinor: 5000, organisationId: 'org-1', actorId: 'actor-1',
            requiredCapability: 'supports_qr',
        );

        $result = $this->pipeline->evaluate($account, $query);

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::CapabilityNotSupported, $result->reason);
    }

    public function test_pipeline_includes_account_with_required_capability(): void
    {
        $provider              = new PaymentProviderModel();
        $provider->is_enabled  = true;
        $provider->is_suspended = false;

        $account                         = new PaymentProviderAccountModel();
        $account->is_active              = true;
        $account->enabled_organisations  = [];
        $account->enabled_modules        = [];
        $account->enabled_purpose_types  = [];
        $account->country_restrictions   = [];
        $account->supported_currencies   = ['BWP'];
        $account->min_amount_minor       = 100;
        $account->max_amount_minor       = 10_000_000;
        $account->capability_flags       = ['supports_qr' => true];
        $account->setRelation('provider', $provider);

        $query = new OptionsQuery(
            purposeType: 'bill', purposeReference: 'ref', currencyCode: 'BWP',
            amountMinor: 5000, organisationId: 'org-1', actorId: 'actor-1',
            requiredCapability: 'supports_qr',
        );

        $result = $this->pipeline->evaluate($account, $query);

        self::assertTrue($result->isEligible);
    }
}
