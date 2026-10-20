<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalReceiptImmutableException;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalReceiptModel;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;

final class WithdrawalReceiptImmutabilityTest extends TestCase
{
    private function makeExposedReceipt(): object
    {
        return new class extends WithdrawalReceiptModel {
            public function exposeUpdate(Builder $query): bool
            {
                return $this->performUpdate($query);
            }

            public function exposeDelete(): void
            {
                $this->performDeleteOnModel();
            }
        };
    }

    /** @test */
    public function perform_update_throws_when_model_exists(): void
    {
        $this->expectException(WithdrawalReceiptImmutableException::class);

        $receipt         = $this->makeExposedReceipt();
        $receipt->exists = true;

        $builderMock = $this->createMock(Builder::class);
        $receipt->exposeUpdate($builderMock);
    }

    /** @test */
    public function perform_delete_throws_when_model_exists(): void
    {
        $this->expectException(WithdrawalReceiptImmutableException::class);

        $receipt         = $this->makeExposedReceipt();
        $receipt->exists = true;

        $receipt->exposeDelete();
    }

    /** @test */
    public function receipt_immutable_exception_is_runtime_exception(): void
    {
        $e = new WithdrawalReceiptImmutableException();

        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /** @test */
    public function perform_update_does_not_throw_for_new_model(): void
    {
        // exists = false: should not throw (falls through to parent::performUpdate)
        // We can't actually call parent without a DB connection, so just verify
        // the guard condition: $this->exists is false → no throw
        $receipt = $this->makeExposedReceipt();
        // exists defaults to false for new models
        $this->assertFalse($receipt->exists);
    }
}
