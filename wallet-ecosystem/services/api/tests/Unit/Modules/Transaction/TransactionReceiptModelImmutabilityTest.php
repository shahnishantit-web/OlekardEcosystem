<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transaction;

use App\Modules\Transaction\Domain\Exceptions\TransactionReceiptImmutableException;
use App\Modules\Transaction\Infrastructure\Models\TransactionReceiptModel;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;

final class TransactionReceiptModelImmutabilityTest extends TestCase
{
    private function makeExposedReceipt(): object
    {
        return new class extends TransactionReceiptModel {
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
        $this->expectException(TransactionReceiptImmutableException::class);

        $receipt         = $this->makeExposedReceipt();
        $receipt->exists = true;

        $builderMock = $this->createMock(Builder::class);
        $receipt->exposeUpdate($builderMock);
    }

    /** @test */
    public function perform_delete_throws_when_model_exists(): void
    {
        $this->expectException(TransactionReceiptImmutableException::class);

        $receipt         = $this->makeExposedReceipt();
        $receipt->exists = true;

        $receipt->exposeDelete();
    }

    /** @test */
    public function receipt_immutable_exception_is_runtime_exception(): void
    {
        $e = new TransactionReceiptImmutableException();

        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /** @test */
    public function new_model_has_exists_false_by_default(): void
    {
        $receipt = $this->makeExposedReceipt();

        $this->assertFalse($receipt->exists);
    }

    /** @test */
    public function receipt_model_has_platform_connection(): void
    {
        $receipt = new TransactionReceiptModel();

        $this->assertSame('platform', $receipt->getConnectionName());
    }

    /** @test */
    public function receipt_model_has_no_auto_incrementing(): void
    {
        $receipt = new TransactionReceiptModel();

        $this->assertFalse($receipt->incrementing);
    }

    /** @test */
    public function receipt_model_has_no_timestamps(): void
    {
        $receipt = new TransactionReceiptModel();

        $this->assertFalse($receipt->timestamps);
    }
}
