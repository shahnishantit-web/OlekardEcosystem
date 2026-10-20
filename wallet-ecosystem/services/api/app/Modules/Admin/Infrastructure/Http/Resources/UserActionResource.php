<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'action_type'  => $data['action_type'] ?? null,
            'actor_id'     => $data['actor_id'] ?? null,
            'subject_id'   => $data['subject_id'] ?? null,
            'performed_at' => $data['performed_at'] ?? null,
        ];
    }
}
