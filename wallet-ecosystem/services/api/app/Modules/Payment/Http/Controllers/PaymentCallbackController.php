<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Integration\Application\Services\WebhookApplicationService;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Backward-compatible URL; all new receipt ownership belongs to E25. */
final class PaymentCallbackController
{
    public function __construct(private readonly WebhookApplicationService $ingress) {}

    public function store(Request $request, string $providerCode): JsonResponse
    {
        $raw = $request->getContent();
        abort_if($raw === '', 400, 'Empty callback body.');
        $mappings = IntegrationModel::whereIn('provider_type', ['payment', 'bank'])
            ->where('configuration->payment_provider_code', $providerCode)->limit(2)->get();
        abort_unless($mappings->count() === 1, 404, 'Use the configured account webhook endpoint.');
        $mapping = $mappings->first();
        if ($mapping === null) {
            abort(404, 'Payment integration is not configured.');
        }
        try {
            $receipt = $this->ingress->receive(
                $mapping->provider_slug, $raw,
                $request->header('X-Signature') ?? $request->header('X-Mock-Bank-Signature') ?? $request->header('X-Hub-Signature-256') ?? '',
                $request->headers->all(), (string) $request->ip(),
            );
        } catch (WebhookSignatureException) {
            abort(401, 'Invalid webhook signature.');
        }
        return response()->json(['message' => 'Accepted.', 'receipt_id' => $receipt->id], 202);
    }
}
