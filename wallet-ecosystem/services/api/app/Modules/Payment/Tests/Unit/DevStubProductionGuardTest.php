<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Payment\Infrastructure\Stubs\DevPurposeStubResolver;
use Tests\TestCase;

final class DevStubProductionGuardTest extends TestCase
{

    public function test_construction_throws_in_production_environment(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(ProductionBlockedException::class);

        new DevPurposeStubResolver();
    }

    public function test_construction_throws_in_staging_environment(): void
    {
        $this->app['env'] = 'staging';

        $this->expectException(ProductionBlockedException::class);

        new DevPurposeStubResolver();
    }

    public function test_construction_succeeds_in_local_environment(): void
    {
        $this->app['env'] = 'local';

        $stub = new DevPurposeStubResolver();

        $this->assertInstanceOf(DevPurposeStubResolver::class, $stub);
    }

    public function test_supported_purpose_type_is_dev_test_purpose(): void
    {
        $this->app['env'] = 'local';

        $stub = new DevPurposeStubResolver();

        $this->assertSame('dev_test_purpose', $stub->supportedPurposeType());
    }

    public function test_resolve_returns_correct_fixed_obligation(): void
    {
        $this->app['env'] = 'local';

        $stub       = new DevPurposeStubResolver();
        $obligation = $stub->resolve('ref-xyz', 'actor-001', 'corr-001');

        $this->assertSame(10000, $obligation->amountMinor);
        $this->assertSame('BWP', $obligation->currencyCode);
        $this->assertSame(DevPurposeStubResolver::TEST_ORG_ID, $obligation->organisationId);
        $this->assertSame('actor-001', $obligation->payerActorId);
        $this->assertNull($obligation->expiresAt);
        $this->assertNull($obligation->holdReference);
        $this->assertSame([], $obligation->beneficiaryAllocations);
    }
}
