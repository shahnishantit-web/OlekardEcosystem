<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transaction;

use App\Modules\Transaction\Domain\Exceptions\TransactionStatusHistoryImmutableException;
use App\Modules\Transaction\Infrastructure\Models\TransactionStatusHistoryModel;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;

final class TransactionStatusHistoryImmutabilityTest extends TestCase
{
    private function makeExposedHistory(): object
    {
        return new class extends TransactionStatusHistoryModel {
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
        $this->expectException(TransactionStatusHistoryImmutableException::class);

        $history         = $this->makeExposedHistory();
        $history->exists = true;

        $builderMock = $this->createMock(Builder::class);
        $history->exposeUpdate($builderMock);
    }

    /** @test */
    public function perform_delete_throws_when_model_exists(): void
    {
        $this->expectException(TransactionStatusHistoryImmutableException::class);

        $history         = $this->makeExposedHistory();
        $history->exists = true;

        $history->exposeDelete();
    }

    /** @test */
    public function history_immutable_exception_is_runtime_exception(): void
    {
        $e = new TransactionStatusHistoryImmutableException();

        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /** @test */
    public function new_model_has_exists_false_by_default(): void
    {
        $history = $this->makeExposedHistory();

        $this->assertFalse($history->exists);
    }

    /** @test */
    public function history_model_has_platform_connection(): void
    {
        $history = new TransactionStatusHistoryModel();

        $this->assertSame('platform', $history->getConnectionName());
    }

    /** @test */
    public function history_model_has_no_timestamps(): void
    {
        $history = new TransactionStatusHistoryModel();

        $this->assertFalse($history->timestamps);
    }
}
