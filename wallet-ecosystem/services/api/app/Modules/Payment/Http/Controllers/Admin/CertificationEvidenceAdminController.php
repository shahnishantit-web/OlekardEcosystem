<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\CertificationEvidenceService;
use App\Modules\Payment\Application\Services\SandboxTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * T067: Certification evidence management and sandbox test execution.
 *
 * Routes (all under payment.configuration middleware):
 *   GET  /v1/admin/payments/banks/{bankId}/accounts/{accountId}/evidence
 *   POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/evidence
 *   POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/sandbox-test
 *
 * SC-007: Sandbox test MUST NOT create payment_intents or payment_attempts rows.
 */
final class CertificationEvidenceAdminController extends Controller
{
    public function __construct(
        private readonly CertificationEvidenceService $evidenceService,
        private readonly SandboxTestService           $sandboxTestService,
    ) {
    }

    public function index(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $evidence = $this->evidenceService->listEvidence($accountId);

        return response()->json(['data' => $evidence->values()]);
    }

    public function store(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'evidence_type'          => 'required|string|max:128',
            'description'            => 'nullable|string|max:2000',
            'reference_url'          => 'nullable|url|max:500',
            'reference_document_key' => 'nullable|string|max:500',
        ]);

        $actorId = (string) $request->user()?->id;

        $evidence = $this->evidenceService->addEvidence($accountId, $validated, $actorId);

        return response()->json(['data' => $evidence], 201);
    }

    /**
     * SC-007: Run a status-query probe only — never initiates a payment.
     */
    public function sandboxTest(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $actorId = (string) $request->user()?->id;

        $result = $this->sandboxTestService->runConnectivityTest($accountId, $actorId);

        $status = $result['result'] === 'pass' ? 200 : 422;

        return response()->json(['data' => $result], $status);
    }
}
