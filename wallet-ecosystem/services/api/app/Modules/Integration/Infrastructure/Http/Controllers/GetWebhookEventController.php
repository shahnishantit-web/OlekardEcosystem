<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Domain\Contracts\WebhookEventRepositoryInterface;
use App\Modules\Integration\Infrastructure\Http\Resources\WebhookEventResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetWebhookEventController
{
    public function __construct(
        private readonly WebhookEventRepositoryInterface $repository,
    ) {}

    public function __invoke(Request $request, string $id): WebhookEventResource
    {
        $event = $this->repository->findById($id);

        if ($event === null) {
            throw new NotFoundHttpException('Webhook event not found.');
        }

        $event->load('processingAttempts');

        return new WebhookEventResource($event);
    }
}
