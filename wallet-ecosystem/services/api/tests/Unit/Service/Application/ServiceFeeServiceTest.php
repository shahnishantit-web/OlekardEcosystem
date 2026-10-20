<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Domain\Exceptions\AmountOutOfRangeException;
use App\Modules\Service\Domain\Exceptions\FeeChangedException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use Tests\TestCase;

final class ServiceFeeServiceTest extends TestCase
{
    private ServiceFeeService $feeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feeService = new ServiceFeeService();
    }

    private function makeService(string $feeType, int $feeValue, int $min = 100, int $max = 1000000): ServiceModel
    {
        $model = new ServiceModel();
        $model->fee_type     = \App\Modules\Service\Domain\Enums\ServiceFeeType::from($feeType);
        $model->fee_value    = $feeValue;
        $model->min_amount   = $min;
        $model->max_amount   = $max;
        $model->currency_code = 'BWP';
        return $model;
    }

    public function test_flat_fee_calculation(): void
    {
        $service = $this->makeService('flat', 200);
        $result  = $this->feeService->calculate($service, 10000);

        $this->assertSame(200, $result['fee_amount']);
        $this->assertSame(10200, $result['total_amount']);
        $this->assertSame(10000, $result['amount']);
        $this->assertSame('BWP', $result['currency_code']);
    }

    public function test_percentage_fee_calculation_basis_points(): void
    {
        // fee_value = 50 means 0.5% (50 basis points)
        $service = $this->makeService('percentage', 50);
        $result  = $this->feeService->calculate($service, 10000);

        // 10000 * 50 / 10000 = 50
        $this->assertSame(50, $result['fee_amount']);
        $this->assertSame(10050, $result['total_amount']);
    }

    public function test_percentage_fee_rounds_correctly(): void
    {
        // fee_value = 150 (1.5%), amount = 333 → 333 * 150 / 10000 = 4.995 → round to 5
        $service = $this->makeService('percentage', 150);
        $result  = $this->feeService->calculate($service, 333);

        $this->assertSame(5, $result['fee_amount']);
    }

    public function test_amount_below_minimum_throws_error(): void
    {
        $service = $this->makeService('flat', 200, 500, 100000);

        $this->expectException(AmountOutOfRangeException::class);
        $this->feeService->calculate($service, 100);
    }

    public function test_amount_above_maximum_throws_error(): void
    {
        $service = $this->makeService('flat', 200, 100, 50000);

        $this->expectException(AmountOutOfRangeException::class);
        $this->feeService->calculate($service, 100000);
    }

    public function test_assert_fee_unchanged_passes_on_match(): void
    {
        $service = $this->makeService('flat', 200);

        // Should not throw
        $this->feeService->assertFeeUnchanged($service, 200, 10000);

        $this->assertTrue(true);
    }

    public function test_assert_fee_unchanged_throws_on_mismatch(): void
    {
        $service = $this->makeService('flat', 300);

        $this->expectException(FeeChangedException::class);
        $this->feeService->assertFeeUnchanged($service, 200, 10000);
    }

    public function test_fee_changed_exception_carries_new_values(): void
    {
        $service = $this->makeService('flat', 500);

        try {
            $this->feeService->assertFeeUnchanged($service, 200, 10000);
            $this->fail('Expected FeeChangedException');
        } catch (FeeChangedException $e) {
            $this->assertSame(500, $e->newFeeAmount);
            $this->assertSame(10500, $e->newTotalAmount);
        }
    }
}
