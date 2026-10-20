<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Resources;

use Illuminate\Http\Request;

final class NotificationDetailResource extends NotificationSummaryResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'mandatory' => (bool) $this->mandatory,
        ]);
    }
}
