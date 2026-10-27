<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Jobs\StatementMatchingJob;
use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Domain\Events\StatementIngestionCompletedEvent;
use App\Modules\Payment\Domain\Exceptions\StatementAlreadyExistsException;
use App\Modules\Payment\Infrastructure\Models\ProviderStatementModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class ProviderStatementService
{
    public function __construct(
        private readonly ProviderStatementRepositoryInterface $repository,
        private readonly OutboxEventPublisher                 $outbox,
        private readonly AuditServiceInterface                $audit,
    ) {}

    /**
     * Ingest a provider statement with atomic line persistence and deduplication.
     *
     * @param array<string, mixed> $statementData
     */
    public function ingest(array $statementData, ?string $actorId): ProviderStatementModel
    {
        $sourceHash = $this->computeSourceHash($statementData);

        $existing = $this->repository->findByHash($sourceHash);
        if ($existing !== null) {
            throw StatementAlreadyExistsException::withHash($sourceHash, $existing->id);
        }

        $statementId = (string) Uuid::uuid7();
        $ingestedAt  = now();

        DB::connection('payment')->transaction(function () use (
            $statementId, $statementData, $sourceHash, $ingestedAt, $actorId
        ): void {
            $lines = $statementData['lines'] ?? [];

            DB::connection('payment')->table('payment.provider_statements')->insert([
                'id'            => $statementId,
                'provider_code' => $statementData['provider_code'],
                'account_id'    => $statementData['account_id'],
                'environment'   => $statementData['environment'],
                'currency_code' => $statementData['currency_code'],
                'period_start'  => $statementData['period_start'],
                'period_end'    => $statementData['period_end'],
                'source_hash'   => $sourceHash,
                'status'        => ProviderStatementStatus::PendingMatch->value,
                'total_lines'   => count($lines),
                'matched_lines' => 0,
                'exception_lines' => 0,
                'submitted_by'  => $actorId,
                'ingested_at'   => $ingestedAt,
                'created_at'    => $ingestedAt,
                'updated_at'    => $ingestedAt,
            ]);

            foreach ($lines as $line) {
                DB::connection('payment')->table('payment.provider_statement_lines')->insert([
                    'id'                   => (string) Uuid::uuid7(),
                    'statement_id'         => $statementId,
                    'line_type'            => $line['line_type'],
                    'provider_reference_id' => $line['provider_reference_id'],
                    'amount_minor'         => (int) $line['amount_minor'],
                    'currency_code'        => $line['currency_code'],
                    'direction'            => $line['direction'],
                    'settled_at'           => $line['settled_at'],
                    'match_status'         => 'pending',
                    'metadata'             => isset($line['metadata']) ? json_encode($line['metadata']) : null,
                    'created_at'           => $ingestedAt,
                    'updated_at'           => $ingestedAt,
                ]);
            }
        });

        $statement = $this->repository->findById($statementId);
        assert($statement !== null);

        $this->audit->record(new AuditEventData(
            action:       'payment.statement.ingested',
            actorType:    $actorId !== null ? 'user' : 'system',
            resourceType: 'provider_statement',
            correlationId: $statementId,
            outcome:      'success',
            actorId:      $actorId,
            resourceId:   $statementId,
            afterState:   [
                'provider_code' => $statementData['provider_code'],
                'total_lines'   => count($statementData['lines'] ?? []),
                'source_hash'   => $sourceHash,
            ],
        ));

        DB::connection('platform')->transaction(function () use ($statement, $ingestedAt): void {
            $this->outbox->publish(new StatementIngestionCompletedEvent(
                statementId:  $statement->id,
                providerCode: $statement->provider_code,
                accountId:    $statement->account_id,
                environment:  $statement->environment,
                currencyCode: $statement->currency_code,
                periodStart:  $statement->period_start->toIso8601String(),
                periodEnd:    $statement->period_end->toIso8601String(),
                totalLines:   $statement->total_lines,
                sourceHash:   $statement->source_hash,
                ingestedAt:   $ingestedAt->toIso8601String(),
            ));
        });

        StatementMatchingJob::dispatch($statementId);

        Log::info('payment.statement.ingested', [
            'statement_id' => $statementId,
            'provider_code' => $statementData['provider_code'],
            'total_lines'   => count($statementData['lines'] ?? []),
        ]);

        return $statement;
    }

    /**
     * Compute SHA-256 content hash for deduplication.
     *
     * Format: SHA-256("{provider_code}|{account_id}|{environment}|{period_start_iso}|{period_end_iso}|{sorted_ref_ids}")
     *
     * @param array<string, mixed> $statementData
     */
    private function computeSourceHash(array $statementData): string
    {
        $lines = $statementData['lines'] ?? [];
        $refIds = array_map(
            static fn (array $l): string => $l['provider_reference_id'],
            $lines
        );
        sort($refIds);

        $canonical = implode('|', [
            $statementData['provider_code'],
            $statementData['account_id'],
            $statementData['environment'],
            $statementData['period_start'],
            $statementData['period_end'],
            implode(',', $refIds),
        ]);

        return hash('sha256', $canonical);
    }
}
