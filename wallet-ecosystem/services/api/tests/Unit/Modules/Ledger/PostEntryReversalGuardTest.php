<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ledger;

use App\Modules\Ledger\Application\Services\LedgerAccountService;
use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Ledger\Domain\Enums\JournalEntryType;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Ledger\Infrastructure\Repositories\EloquentJournalRepository;
use Tests\Support\TestCase;

/**
 * T092 — FR-E07-044: postEntry() must reject entry_type = reversal.
 *
 * Verifies that LedgerPostingService::post() throws InvalidArgumentException when
 * entry_type = "reversal" is supplied, enforcing that reversal journals are only
 * created internally by LedgerReverseService.
 */
final class PostEntryReversalGuardTest extends TestCase
{
    public function test_post_throws_when_entry_type_is_reversal(): void
    {
        $service = new LedgerPostingService(
            accountService:     $this->createStub(LedgerAccountService::class),
            journalRepo:        $this->createStub(EloquentJournalRepository::class),
            idempotencyService: $this->createStub(IdempotencyServiceInterface::class),
            outboxPublisher:    $this->createStub(EventPublisherInterface::class),
            auditService:       $this->createStub(AuditServiceInterface::class),
            metrics:            $this->createStub(MetricsCollectorInterface::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reversal.*internal/i');

        $service->post(
            request: [
                'idempotency_key'       => 'idem-reversal-guard',
                'correlation_id'        => 'corr-001',
                'currency_code'         => 'KES',
                'transaction_reference' => 'TXN-REVERSAL-GUARD',
                'lines'                 => [
                    ['ledger_account_reference' => 'acc-a', 'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-b', 'direction' => 'credit', 'amount' => 1000],
                ],
            ],
            actorId:   'actor-001',
            entryType: JournalEntryType::Reversal->value,
        );
    }
}
