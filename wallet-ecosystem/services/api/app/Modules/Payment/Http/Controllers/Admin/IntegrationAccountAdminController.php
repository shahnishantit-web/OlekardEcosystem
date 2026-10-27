<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\IntegrationAccountService;
use App\Modules\Payment\Domain\Enums\AccountConfigLifecycleStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Thin controller — all business logic in IntegrationAccountService.
 * Requires payment.configuration permission (enforced via route middleware).
 */
final class IntegrationAccountAdminController extends Controller
{
    public function __construct(
        private readonly IntegrationAccountService $service,
    ) {
    }

    public function index(Request $request, string $bankId): JsonResponse
    {
        $filters   = $request->only(['status']);
        $accounts  = $this->service->listAccounts($bankId, $filters);

        return response()->json(['data' => $accounts->map(fn($a) => $this->safeAccountShape($a))->values()]);
    }

    public function show(string $bankId, string $accountId): JsonResponse
    {
        $account = $this->service->findAccount($accountId);

        if ($account === null || $account->provider_id !== $bankId) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Account not found.']], 404);
        }

        return response()->json(['data' => $this->safeAccountShape($account)]);
    }

    public function store(Request $request, string $bankId): JsonResponse
    {
        $validated = $request->validate([
            'merchant_account_reference' => 'required|string|max:255',
            'environment'                => 'required|string|in:sandbox,production',
            'supported_currencies'       => 'required|array|min:1',
            'supported_currencies.*'     => 'string|size:3',
            'display_label'              => 'nullable|string|max:255',
            'display_sort_order'         => 'nullable|integer|min:0',
        ]);

        $validated['provider_id'] = $bankId;
        $actorId = (string) $request->user()?->id;

        $account = $this->service->createAccount($validated, $actorId);

        return response()->json(['data' => $this->safeAccountShape($account)], 201);
    }

    public function update(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'merchant_account_reference' => 'sometimes|string|max:255',
            'supported_currencies'       => 'sometimes|array',
            'supported_currencies.*'     => 'string|size:3',
            'display_label'              => 'nullable|string|max:255',
            'display_sort_order'         => 'nullable|integer|min:0',
            'config_version'             => 'required|integer|min:1',
        ]);

        $actorId = (string) $request->user()?->id;
        $version = (int) $validated['config_version'];

        $account = $this->service->updateAccount($accountId, $validated, $version, $actorId);

        $isActive = $account->account_lifecycle_status !== AccountConfigLifecycleStatus::Active->value;
        $draftCreated = !$isActive && $account->provider_id === $bankId;

        return response()->json([
            'data'          => $this->safeAccountShape($account),
            'draft_created' => $draftCreated,
        ]);
    }

    /**
     * Returns account shape with callback_secret_reference masked (FR-014).
     *
     * @return array<string, mixed>
     */
    private function safeAccountShape(\App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel $account): array
    {
        $data = $account->toArray();

        if (isset($data['callback_secret_reference']) && is_string($data['callback_secret_reference'])) {
            $data['callback_secret_reference'] = ['key' => $data['callback_secret_reference'], 'masked' => '****'];
        }

        return $data;
    }
}
