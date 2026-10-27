<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Illuminate\Support\Carbon;

final readonly class StatementMatchingCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string                 $statementId,
        private ProviderStatementStatus $finalStatus,
        private int                    $matchedLines,
        private int                    $exceptionLines,
        private int                    $totalLines,
        private Carbon                 $completedAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.statement.matching_completed';
    }

    public function occurredAt(): string
    {
        return $this->completedAt->toIso8601String();
    }

    public function correlationId(): string
    {
        return $this->statementId;
    }

    public function aggregateType(): string
    {
        return 'provider_statement';
    }

    public function aggregateId(): string
    {
        return $this->statementId;
    }

    public function idempotencyKey(): string
    {
        return "statement:matched:{$this->statementId}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'           => $this->eventName(),
            'statement_id'    => $this->statementId,
            'final_status'    => $this->finalStatus->value,
            'matched_lines'   => $this->matchedLines,
            'exception_lines' => $this->exceptionLines,
            'total_lines'     => $this->totalLines,
            'completed_at'    => $this->completedAt->toIso8601String(),
        ];
    }
}
