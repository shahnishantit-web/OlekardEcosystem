<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\WebhookApplicationService;
use App\Modules\Integration\Domain\Exceptions\WebhookNotDeadLetterException;
use App\Modules\Integration\Infrastructure\Http\Resources\WebhookEventResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RequeueWebhookEventController
{
    public function __construct(
        private readonly WebhookApplicationService $service,
    ) {}

    public function __invoke(Request $request, string $id): WebhookEventResource
    {
        try {
            $event = $this->service->requeue($id);
        } catch (WebhookNotDeadLetterException $e) {
            throw new ConflictHttpException($e->getMessage());
        } catch (\RuntimeException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return new WebhookEventResource($event);
    }
}
