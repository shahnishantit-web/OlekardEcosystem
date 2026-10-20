<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\PaymentCallbackService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentCallbackModel;
use App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * T130: Processes a raw payment callback asynchronously.
 *
 * Processing steps:
 * 1. Load PaymentCallbackModel.
 * 2. Resolve ProviderWebhookContract for provider_code.
 * 3. Parse + verify signature.
 * 4. Idempotency check — if already processed, mark duplicate and return.
 * 5. Out-of-order check — if attempt is terminal, mark duplicate and return.
 * 6. Call PaymentOrchestrationService.handleOutcome.
 * 7. Mark callback processed.
 * 8. On dead-letter: create ReconciliationException + emit alert.
 */
final class PaymentCallbackProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $callbackId,
    ) {
    }

    public function handle(
        PaymentCallbackService              $callbackService,
        PaymentOrchestrationService         $orchestrationService,
        PaymentAttemptRepositoryInterface   $attemptRepository,
    ): void {
        $callback = PaymentCallbackModel::query()->find($this->callbackId);

        if ($callback === null) {
            Log::error('PaymentCallbackProcessingJob: callback not found', ['id' => $this->callbackId]);
            return;
        }

        $providerCode = $callback->provider_code;

        // Step 1: resolve parser
        try {
            $parser = $callbackService->resolveParser($providerCode);
        } catch (ProviderException $e) {
            $this->markCallbackError($callback, 'no_parser_registered', $e->getMessage());
            return;
        }

        $rawBody   = $callback->raw_body;
        $signature = $callback->signature_header ?? '';

        /** @var array<string, string> $headers */
        $headers = is_array($callback->headers) ? $callback->headers : [];

        // Step 2: parse + verify signature
        try {
            $event = $parser->parse($rawBody, $signature, $headers);
        } catch (WebhookSignatureException $e) {
            $this->markCallbackError($callback, 'signature_invalid', $e->getMessage());
            return;
        } catch (ProviderException $e) {
            $this->markCallbackError($callback, 'parse_error', $e->getMessage());
            return;
        }

        // Step 3: idempotency — check if this event was already processed
        $alreadyProcessed = PaymentCallbackModel::query()
            ->where('idempotency_key', $event->idempotencyKey)
            ->where('status', 'processed')
            ->where('id', '!=', $this->callbackId)
            ->exists();

        if ($alreadyProcessed) {
            $this->markCallbackDuplicate($callback);
            return;
        }

        $callback->idempotency_key = $event->idempotencyKey;
        $callback->save();

        // Step 4: determine outcome string
        $outcome = match ($event->status) {
            ProviderStatus::SUCCESS => 'succeeded',
            ProviderStatus::FAILURE => 'failed',
            default                 => null,
        };

        if ($outcome === null) {
            Log::info('PaymentCallbackProcessingJob: unknown outcome, skipping', [
                'callback_id'  => $this->callbackId,
                'provider_key' => $event->providerKey,
            ]);
            $this->markCallbackError($callback, 'unknown_outcome', 'ProviderStatus: '.$event->status->value);
            return;
        }

        // Step 5: look up attempt and check out-of-order
        $platformEntityId = $event->platformEntityId;

        if ($platformEntityId === null) {
            $this->markCallbackError($callback, 'unresolved_entity', 'No platform entity ID could be resolved.');
            return;
        }

        $attempt = $attemptRepository->findById($platformEntityId);
        if ($attempt === null) {
            $this->markCallbackError($callback, 'attempt_not_found', 'Payment attempt not found: '.$platformEntityId);
            return;
        }

        // Out-of-order check: if attempt already terminal, mark duplicate
        $attemptStatusValue = $attempt->status instanceof \BackedEnum
            ? $attempt->status->value
            : (string) $attempt->status;

        $terminalStatuses = ['succeeded', 'failed', 'cancelled', 'expired'];
        if (in_array($attemptStatusValue, $terminalStatuses, true)) {
            $this->markCallbackDuplicate($callback);
            return;
        }

        try {
            $orchestrationService->handleOutcome([
                'payment_intent_id'  => $attempt->payment_intent_id,
                'payment_attempt_id' => $platformEntityId,
                'outcome'            => $outcome,
                'provider_reference_id' => $event->providerReference,
                'fee_minor'          => 0,
                'correlation_id'     => $event->correlationId,
            ]);

            $callback->status       = 'processed';
            $callback->processed_at = now();
            $callback->save();
        } catch (\Throwable $e) {
            Log::error('PaymentCallbackProcessingJob: handleOutcome failed', [
                'callback_id' => $this->callbackId,
                'error'       => get_class($e),
            ]);
            throw $e; // Let queue retry
        }
    }

    /**
     * Handle job failure after max retries — create reconciliation exception.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PaymentCallbackProcessingJob: dead-lettered', [
            'callback_id' => $this->callbackId,
            'error'       => $exception->getMessage(),
        ]);

        try {
            $rec = new ReconciliationExceptionModel();
            $rec->id           = (string) Uuid::uuid7();
            $rec->exception_type = 'missing_callback';
            $rec->provider_code  = 'unknown';
            $rec->description    = 'Callback processing failed after max retries. Callback ID: '.$this->callbackId;
            $rec->status         = 'open';
            $rec->save();
        } catch (\Throwable) {
            // Best-effort; log was already emitted above.
        }
    }

    private function markCallbackDuplicate(PaymentCallbackModel $callback): void
    {
        $callback->status = 'duplicate';
        $callback->save();
    }

    private function markCallbackError(PaymentCallbackModel $callback, string $status, string $message): void
    {
        $callback->status        = $status;
        $callback->error_message = $message;
        $callback->save();
    }
}
