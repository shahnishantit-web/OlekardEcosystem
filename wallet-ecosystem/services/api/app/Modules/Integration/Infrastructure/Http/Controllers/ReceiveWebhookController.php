<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\WebhookApplicationService;
use App\Modules\Integration\Domain\Exceptions\WebhookProviderNotFoundException;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReceiveWebhookController
{
    public function __construct(
        private readonly WebhookApplicationService $service,
    ) {}

    public function __invoke(Request $request, string $providerSlug): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = (string) $request->header('X-Signature', '');
        $headers = $request->headers->all();
        $sourceIp = (string) $request->ip();

        try {
            $event = $this->service->receive(
                $providerSlug,
                $rawPayload,
                $signature,
                $headers,
                $sourceIp,
            );
        } catch (WebhookProviderNotFoundException) {
            throw new NotFoundHttpException("Unknown provider: {$providerSlug}");
        }

        return response()->json([
            'success' => true,
            'data' => [
                'webhook_event_id' => $event->id,
                'status' => $event->status->value,
            ],
        ]);
    }
}
