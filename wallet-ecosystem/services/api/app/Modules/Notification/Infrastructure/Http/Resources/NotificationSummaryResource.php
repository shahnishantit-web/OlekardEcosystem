<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'notification_type' => $this->notification_type,
            'category'          => $this->category instanceof \App\Modules\Notification\Domain\Enums\NotificationCategory
                ? $this->category->value
                : $this->category,
            'subject'           => $this->subject,
            'body'              => $this->body,
            'read'              => $this->read_at !== null,
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
