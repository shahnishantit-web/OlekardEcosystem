<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Jobs\PayoutQualificationJob;
use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Payment\Domain\Enums\StatementLineType;
use App\Modules\Payment\Domain\Events\StatementMatchingCompletedEvent;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StatementMatchingService
{
    public function __construct(
        private readonly PaymentAttemptRepositoryInterface      $attemptRepository,
        private readonly PaymentRefundRepositoryInterface       $refundRepository,
        private readonly PaymentDisputeCaseRepositoryInterface  $disputeRepository,
        private readonly ClearingRecordRepositoryInterface      $clearingRepository,
        private readonly ProviderStatementRepositoryInterface   $statementRepository,
        private readonly ReconciliationExceptionService         $exceptionService,
        private readonly ClearingService                        $clearingService,
        private readonly OutboxEventPublisher                   $outbox,
    ) {}

    public function matchStatement(string $statementId): void
    {
        $statement = $this->statementRepository->findById($statementId);
        if ($statement === null) {
            Log::error('payment.statement.matching_not_found', ['statement_id' => $statementId]);
            return;
        }

        $matchedCount   = 0;
        $exceptionCount = 0;
        $matchedAttemptIds = [];

        // Process lines in chunks
        $statement->lines()->orderBy('id')->chunk(200, function ($lines) use (
            $statement, &$matchedCount, &$exceptionCount, &$matchedAttemptIds
        ): void {
            foreach ($lines as $line) {
                $lineType = $line->line_type;

                if ($lineType === StatementLineType::InternalTransfer) {
                    DB::connection('payment')->table('payment.provider_statement_lines')
                        ->where('id', $line->id)
                        ->update([
                            'match_status' => StatementLineMatchStatus::InternalTransfer->value,
                            'updated_at'   => now(),
                        ]);
                    continue;
                }

                $matchResult = $this->matchLine($line, $statement);

                DB::connection('payment')->table('payment.provider_statement_lines')
                    ->where('id', $line->id)
                    ->update(array_merge(['updated_at' => now()], $matchResult['lineUpdate']));

                if ($matchResult['matched']) {
                    $matchedCount++;
                    if ($lineType === StatementLineType::Capture && isset($matchResult['attemptId'])) {
                        $matchedAttemptIds[] = $matchResult['attemptId'];
                        $this->clearingService->createFromMatch(
                            attemptId:           $matchResult['attemptId'],
                            statementId:         $statement->id,
                            statementLineId:     $line->id,
                            providerCode:        $statement->provider_code,
                            currencyCode:        $line->currency_code,
                            clearedAmountMinor:  $line->amount_minor,
                            attemptAmountMinor:  $line->amount_minor,
                        );
                    }
                } else {
                    $exceptionCount++;
                }
            }
        });

        // Bottom-up MissingCallback detection
        $this->detectMissingCallbacks($statement, $matchedAttemptIds);

        // Compute final statement status
        $finalStatus = $exceptionCount > 0
            ? ProviderStatementStatus::PartiallyMatched
            : ProviderStatementStatus::Matched;

        $this->statementRepository->updateStatus($statementId, $finalStatus, [
            'matched_lines'          => $matchedCount,
            'exception_lines'        => $exceptionCount,
            'matching_completed_at'  => now(),
        ]);

        // Publish matching completed event
        DB::connection('platform')->transaction(function () use ($statement, $finalStatus, $matchedCount, $exceptionCount): void {
            $this->outbox->publish(new StatementMatchingCompletedEvent(
                statementId:    $statement->id,
                finalStatus:    $finalStatus,
                matchedLines:   $matchedCount,
                exceptionLines: $exceptionCount,
                totalLines:     $statement->total_lines,
                completedAt:    now(),
            ));
        });

        // Chain payout qualification if statement is matchable
        if ($finalStatus->allowsQualification()) {
            PayoutQualificationJob::dispatch($statementId);
        }

        Log::info('payment.statement.matching_completed', [
            'statement_id'   => $statementId,
            'final_status'   => $finalStatus->value,
            'matched_lines'  => $matchedCount,
            'exception_lines' => $exceptionCount,
        ]);
    }

    /**
     * @param  \App\Modules\Payment\Infrastructure\Models\ProviderStatementLineModel $line
     * @param  \App\Modules\Payment\Infrastructure\Models\ProviderStatementModel $statement
     * @return array{matched: bool, lineUpdate: array<string, mixed>, attemptId?: string}
     */
    private function matchLine(object $line, object $statement): array
    {
        $lineType = $line->line_type;

        if ($lineType === StatementLineType::Capture) {
            return $this->matchCaptureLine($line, $statement);
        }

        if ($lineType === StatementLineType::Fee) {
            return $this->matchFeeLine($line, $statement);
        }

        if ($lineType === StatementLineType::Refund) {
            return $this->matchRefundLine($line, $statement);
        }

        if (in_array($lineType, [StatementLineType::Reversal, StatementLineType::Chargeback], true)) {
            return $this->matchDisputeLine($line, $statement);
        }

        // Adjustment: mark matched without entity link
        return [
            'matched'    => true,
            'lineUpdate' => [
                'match_status'        => StatementLineMatchStatus::Matched->value,
                'matched_entity_type' => 'adjustment',
            ],
        ];
    }

    /** @return array{matched: bool, lineUpdate: array<string, mixed>, attemptId?: string} */
    private function matchCaptureLine(object $line, object $statement): array
    {
        $attempt = DB::connection('payment')
            ->table('payment.payment_attempts')
            ->where('provider_reference_id', $line->provider_reference_id)
            ->where('provider_code', $statement->provider_code)
            ->where('status', 'succeeded')
            ->first();

        if ($attempt === null) {
            $this->exceptionService->create(ReconciliationExceptionType::OrphanProvider, [
                'payment_attempt_id'    => null,
                'provider_code'         => $statement->provider_code,
                'provider_reference_id' => $line->provider_reference_id,
                'detected_amount_minor' => $line->amount_minor,
                'expected_amount_minor' => null,
                'currency_code'         => $line->currency_code,
                'description'           => "Capture line in statement with no matching succeeded attempt: {$line->provider_reference_id}",
                'owner'                 => 'finance_ops',
            ]);

            return [
                'matched'    => false,
                'lineUpdate' => ['match_status' => StatementLineMatchStatus::NoPlatformRecord->value],
            ];
        }

        if ((int) $attempt->amount_minor !== $line->amount_minor) {
            $this->exceptionService->create(ReconciliationExceptionType::AmountMismatch, [
                'payment_attempt_id'    => $attempt->id,
                'provider_code'         => $statement->provider_code,
                'provider_reference_id' => $line->provider_reference_id,
                'detected_amount_minor' => $line->amount_minor,
                'expected_amount_minor' => (int) $attempt->amount_minor,
                'currency_code'         => $line->currency_code,
                'description'           => "Amount mismatch on capture: statement={$line->amount_minor}, platform={$attempt->amount_minor}",
                'owner'                 => 'finance_ops',
            ]);

            return [
                'matched'    => false,
                'lineUpdate' => ['match_status' => StatementLineMatchStatus::AmountMismatch->value],
            ];
        }

        return [
            'matched'   => true,
            'attemptId' => $attempt->id,
            'lineUpdate' => [
                'match_status'        => StatementLineMatchStatus::Matched->value,
                'matched_entity_type' => 'payment_attempt',
                'matched_entity_id'   => $attempt->id,
            ],
        ];
    }

    /** @return array{matched: bool, lineUpdate: array<string, mixed>} */
    private function matchFeeLine(object $line, object $statement): array
    {
        $attempt = DB::connection('payment')
            ->table('payment.payment_attempts')
            ->where('provider_reference_id', $line->provider_reference_id)
            ->where('provider_code', $statement->provider_code)
            ->first();

        if ($attempt === null) {
            $this->exceptionService->create(ReconciliationExceptionType::OrphanFee, [
                'payment_attempt_id'    => null,
                'provider_code'         => $statement->provider_code,
                'provider_reference_id' => $line->provider_reference_id,
                'detected_amount_minor' => $line->amount_minor,
                'currency_code'         => $line->currency_code,
                'description'           => "Fee line with no matching platform capture: {$line->provider_reference_id}",
                'owner'                 => 'finance_ops',
            ]);

            return [
                'matched'    => false,
                'lineUpdate' => ['match_status' => StatementLineMatchStatus::NoPlatformRecord->value],
            ];
        }

        return [
            'matched'    => true,
            'lineUpdate' => [
                'match_status'        => StatementLineMatchStatus::Matched->value,
                'matched_entity_type' => 'payment_attempt',
                'matched_entity_id'   => $attempt->id,
            ],
        ];
    }

    /** @return array{matched: bool, lineUpdate: array<string, mixed>} */
    private function matchRefundLine(object $line, object $statement): array
    {
        $refund = DB::connection('payment')
            ->table('payment.payment_refunds')
            ->where('provider_submitted_ref', $line->provider_reference_id)
            ->where('amount_minor', $line->amount_minor)
            ->first();

        if ($refund === null) {
            $this->exceptionService->create(ReconciliationExceptionType::UnmatchedLedger, [
                'provider_code'         => $statement->provider_code,
                'provider_reference_id' => $line->provider_reference_id,
                'detected_amount_minor' => $line->amount_minor,
                'currency_code'         => $line->currency_code,
                'description'           => "Refund line not matched to any platform refund: {$line->provider_reference_id}",
                'owner'                 => 'finance_ops',
            ]);

            return [
                'matched'    => false,
                'lineUpdate' => ['match_status' => StatementLineMatchStatus::NoPlatformRecord->value],
            ];
        }

        return [
            'matched'    => true,
            'lineUpdate' => [
                'match_status'        => StatementLineMatchStatus::Matched->value,
                'matched_entity_type' => 'payment_refund',
                'matched_entity_id'   => $refund->id,
            ],
        ];
    }

    /** @return array{matched: bool, lineUpdate: array<string, mixed>} */
    private function matchDisputeLine(object $line, object $statement): array
    {
        $disputeCase = $this->disputeRepository->findByProviderRef(
            $statement->provider_code,
            $line->provider_reference_id
        );

        if ($disputeCase === null) {
            $this->exceptionService->create(ReconciliationExceptionType::UnmatchedDisputeEvent, [
                'provider_code'         => $statement->provider_code,
                'provider_reference_id' => $line->provider_reference_id,
                'detected_amount_minor' => $line->amount_minor,
                'currency_code'         => $line->currency_code,
                'description'           => "Dispute/chargeback line not matched to any platform dispute: {$line->provider_reference_id}",
                'owner'                 => 'finance_ops',
            ]);

            return [
                'matched'    => false,
                'lineUpdate' => ['match_status' => StatementLineMatchStatus::NoPlatformRecord->value],
            ];
        }

        return [
            'matched'    => true,
            'lineUpdate' => [
                'match_status'        => StatementLineMatchStatus::Matched->value,
                'matched_entity_type' => 'payment_dispute_case',
                'matched_entity_id'   => $disputeCase->id,
            ],
        ];
    }

    /**
     * Bottom-up MissingCallback detection: find succeeded attempts within the period
     * that were NOT included in the statement's matched lines.
     *
     * @param string[] $matchedAttemptIds
     */
    private function detectMissingCallbacks(object $statement, array $matchedAttemptIds): void
    {
        $query = DB::connection('payment')
            ->table('payment.payment_attempts')
            ->where('status', 'succeeded')
            ->where('provider_code', $statement->provider_code)
            ->whereNotNull('provider_reference_id')
            ->whereBetween('created_at', [$statement->period_start, $statement->period_end]);

        if (!empty($matchedAttemptIds)) {
            $query->whereNotIn('id', $matchedAttemptIds);
        }

        $orphanedAttempts = $query->get();

        foreach ($orphanedAttempts as $attempt) {
            $this->exceptionService->create(ReconciliationExceptionType::MissingCallback, [
                'payment_attempt_id'    => $attempt->id,
                'provider_code'         => $statement->provider_code,
                'provider_reference_id' => $attempt->provider_reference_id,
                'detected_amount_minor' => null,
                'expected_amount_minor' => (int) $attempt->amount_minor,
                'currency_code'         => $attempt->currency_code ?? null,
                'description'           => "Succeeded attempt not found in provider statement for period: {$attempt->provider_reference_id}",
                'owner'                 => 'finance_ops',
            ]);
        }
    }
}
