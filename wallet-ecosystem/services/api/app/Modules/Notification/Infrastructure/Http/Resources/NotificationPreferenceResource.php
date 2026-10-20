<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class NotificationPreferenceResource extends JsonResource
{
    public function withResponse(Request $request, \Illuminate\Http\JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }

    public function toArray(Request $request): array
    {
        return [
            'user_id'                  => $this->user_id,
            'push_enabled_categories'  => $this->push_enabled_categories ?? [],
            'sms_enabled_categories'   => $this->sms_enabled_categories ?? [],
            'email_enabled_categories' => $this->email_enabled_categories ?? [],
            'marketing_opted_in'       => (bool) $this->marketing_opted_in,
            'updated_at'               => $this->updated_at?->toIso8601String(),
        ];
    }
}
