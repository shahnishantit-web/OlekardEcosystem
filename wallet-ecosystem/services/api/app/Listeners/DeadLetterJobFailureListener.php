<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Modules\Platform\Domain\Contracts\DeadLetterServiceInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * E27 FR-013: Captures failed queue jobs into the dead-letter store.
 *
 * Registered in PlatformServiceProvider::boot().
 */
final class DeadLetterJobFailureListener
{
    public function __construct(
        private readonly DeadLetterServiceInterface $deadLetterService,
    ) {}

    public function handle(JobFailed $event): void
    {
        $payload = $event->job->payload();

        // Unserialize the command to inspect job-level properties
        $command = null;
        $commandData = $payload['data']['command'] ?? null;
        if (is_string($commandData)) {
            try {
                $command = unserialize($commandData);
            } catch (\Throwable) {
                $command = null;
            }
        }

        $operationType   = $this->deriveOperationType($payload, $command);
        $sourceId        = $this->extractSourceId($command);
        $correlationId   = $this->extractProperty($command, 'correlationId') ?? (string) Uuid::uuid7();
        $retryHistory    = $this->extractProperty($command, 'retryHistory') ?? [];
        $retryCount      = is_array($retryHistory) ? count($retryHistory) : 0;
        $failureReason   = $event->exception->getMessage();

        $capturePayload = [
            'queue'          => $event->job->getQueue(),
            'connection'     => $event->connectionName,
            'job_class'      => $payload['displayName'] ?? ($payload['job'] ?? ''),
            'job_id'         => $payload['uuid'] ?? $payload['id'] ?? null,
        ];

        try {
            $this->deadLetterService->capture(
                operationType: $operationType,
                sourceId:      $sourceId,
                payload:       $capturePayload,
                failureReason: $failureReason,
                retryCount:    $retryCount,
                retryHistory:  is_array($retryHistory) ? $retryHistory : [],
                correlationId: $correlationId,
            );
        } catch (\Throwable $e) {
            // Never let dead-letter capture fail silently — log and swallow.
            Log::error('platform.dead_letter.capture_failed', [
                'operation_type' => $operationType,
                'source_id'      => $sourceId,
                'error'          => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);
        }
    }

    private function deriveOperationType(array $payload, mixed $command): string
    {
        $className = $payload['displayName'] ?? null;

        if ($className === null && $command !== null) {
            $className = get_class($command);
        }

        if ($className === null) {
            return 'unknown.job';
        }

        // Get short class name, strip "Job" suffix, convert to snake_case
        $parts     = explode('\\', $className);
        $shortName = end($parts);
        $stripped  = preg_replace('/Job$/', '', $shortName);

        // Split on capital letters to build dot-separated snake_case segments
        // e.g. "FundingInitiation" -> "funding.initiation"
        // e.g. "TransferTransactionTimeout" -> "transfer.transaction_timeout"
        $words = preg_split('/(?=[A-Z])/', (string) $stripped, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return Str::snake($stripped);
        }

        // Group first word as module, rest as operation snake-cased
        $module    = strtolower(array_shift($words));
        $operation = implode('_', array_map('strtolower', $words));

        return $operation !== '' ? "{$module}.{$operation}" : $module;
    }

    private function extractSourceId(mixed $command): string
    {
        $value = $this->extractProperty($command, 'sourceId')
            ?? $this->extractProperty($command, 'transactionId')
            ?? $this->extractProperty($command, 'transferId')
            ?? $this->extractProperty($command, 'withdrawalId')
            ?? $this->extractProperty($command, 'fundingId');

        return is_string($value) && $value !== '' ? $value : (string) Uuid::uuid7();
    }

    private function extractProperty(mixed $command, string $property): mixed
    {
        if ($command === null || !is_object($command)) {
            return null;
        }

        try {
            return $command->{$property} ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
