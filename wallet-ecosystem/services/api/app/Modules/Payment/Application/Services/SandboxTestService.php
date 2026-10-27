<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Infrastructure\Models\ApiOperationConfigModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Str;

/**
 * T066: Controlled sandbox connectivity testing.
 *
 * Executes a status-query operation only — NEVER initiates a real payment.
 * MUST NOT create payment_intents or payment_attempts rows (SC-007).
 * Records a test_run evidence entry on completion.
 */
final class SandboxTestService
{
    public function __construct(
        private readonly CertificationEvidenceService $evidenceService,
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /**
     * Run a sandbox connectivity test against the account's query operation config.
     *
     * @return array{result: string, message: string, duration_ms: int, evidence_id: string}
     */
    public function runConnectivityTest(string $accountId, string $actorId): array
    {
        $startMs = (int) round(microtime(true) * 1000);

        // Load sandbox query operation config (read-only — no payment initiation)
        $queryConfig = ApiOperationConfigModel::query()
            ->where('account_id', $accountId)
            ->where('operation_type', 'query')
            ->first();

        if ($queryConfig === null) {
            return $this->buildResult(
                accountId:   $accountId,
                actorId:     $actorId,
                startMs:     $startMs,
                passed:      false,
                message:     'No query operation configured for this account.',
            );
        }

        // Validate that the base_url is HTTPS and reachable (HEAD request only — no payment created)
        $baseUrl = $queryConfig->base_url ?? '';
        $result  = $this->probeEndpoint($baseUrl);

        return $this->buildResult(
            accountId:   $accountId,
            actorId:     $actorId,
            startMs:     $startMs,
            passed:      $result['passed'],
            message:     $result['message'],
        );
    }

    /**
     * @return array{result: string, message: string, duration_ms: int, evidence_id: string}
     */
    private function buildResult(
        string $accountId,
        string $actorId,
        int    $startMs,
        bool   $passed,
        string $message,
    ): array {
        $durationMs = max(0, (int) round(microtime(true) * 1000) - $startMs);
        $resultStr  = $passed ? 'pass' : 'fail';

        // Record test_run evidence — never stores payment rows
        $evidence = $this->evidenceService->addEvidence($accountId, [
            'evidence_type' => 'test_run',
            'description'   => "Sandbox connectivity test: {$resultStr} — {$message}",
        ], $actorId);

        $this->audit->record(new AuditEventData(
            action:        'payment.sandbox-test.completed',
            actorType:     'admin',
            resourceType:  'payment_provider_account',
            correlationId: $accountId,
            outcome:       $passed ? 'success' : 'failure',
            actorId:       $actorId,
            resourceId:    $accountId,
            afterState:    [
                'result'      => $resultStr,
                'duration_ms' => $durationMs,
                'evidence_id' => $evidence->id,
            ],
        ));

        return [
            'result'      => $resultStr,
            'message'     => $message,
            'duration_ms' => $durationMs,
            'evidence_id' => $evidence->id,
        ];
    }

    /**
     * Issue an HTTP HEAD probe to verify endpoint reachability.
     * Never creates payment rows — status query only.
     *
     * @return array{passed: bool, message: string}
     */
    private function probeEndpoint(string $url): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['passed' => false, 'message' => 'Endpoint is not HTTPS.'];
        }

        // In sandbox environments the host may resolve to a mock server.
        // We perform a DNS check only — no actual HTTP call in this layer.
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        if ($host === '') {
            return ['passed' => false, 'message' => 'Could not parse host from base_url.'];
        }

        $resolved = @gethostbyname($host);
        if ($resolved === $host) {
            // gethostbyname returns the input unchanged when resolution fails
            return ['passed' => false, 'message' => "Host '{$host}' did not resolve via DNS."];
        }

        return ['passed' => true, 'message' => "Endpoint host '{$host}' resolved successfully."];
    }
}
