<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * P20: Read-only controller listing config-change drafts scoped to a specific provider.
 *
 * High-risk fields within change_payload are masked before returning to callers.
 * Permission enforcement via route middleware only — no $this->authorize().
 */
final class ProviderConfigDraftListAdminController extends Controller
{
    /** @var string[] High-risk fields to mask in change_payload responses */
    private const HIGH_RISK_FIELDS = [
        'merchant_account_reference',
        'callback_secret_reference',
        'settlement_destination',
    ];

    public function index(Request $request, string $providerId): JsonResponse
    {
        $query = PaymentConfigChangeDraftModel::on('payment')
            ->where('provider_id', $providerId)
            ->orderByDesc('created_at');

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $paginated = $query->paginate(20);

        $items = $paginated->getCollection()->map(function (PaymentConfigChangeDraftModel $draft): array {
            return $this->safeDraftShape($draft);
        })->all();

        return response()->json([
            'data'       => $items,
            'pagination' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $providerId, string $draftId): JsonResponse
    {
        /** @var PaymentConfigChangeDraftModel $draft */
        $draft = PaymentConfigChangeDraftModel::on('payment')
            ->where('id', $draftId)
            ->where('provider_id', $providerId)
            ->firstOrFail();

        return response()->json([
            'data' => $this->safeDraftShape($draft),
        ]);
    }

    /**
     * Return a safe representation of a draft with high-risk payload fields masked.
     *
     * @return array<string, mixed>
     */
    private function safeDraftShape(PaymentConfigChangeDraftModel $draft): array
    {
        return [
            'id'                   => $draft->id,
            'provider_id'          => $draft->provider_id,
            'change_type'          => $draft->change_type,
            'change_payload'       => $this->maskPayload($draft->change_payload ?? []),
            'status'               => $draft->status->value,
            'drafted_by_actor_id'  => $draft->drafted_by_actor_id,
            'reviewed_by_actor_id' => $draft->reviewed_by_actor_id,
            'reviewed_at'          => $draft->reviewed_at?->toIso8601String(),
            'review_notes'         => $draft->review_notes,
            'created_at'           => $draft->created_at?->toIso8601String(),
            'updated_at'           => $draft->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Replace high-risk field values in a payload array with '****'.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function maskPayload(array $payload): array
    {
        foreach (self::HIGH_RISK_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '****';
            }
        }

        return $payload;
    }
}
