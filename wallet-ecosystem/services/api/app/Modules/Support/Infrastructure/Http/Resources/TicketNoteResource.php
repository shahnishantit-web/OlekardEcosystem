<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TicketNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'ticket_id'   => $this->ticket_id,
            'author_id'   => $this->author_id,
            'author_type' => $this->author_type,
            'content'     => $this->content,
            'is_internal' => (bool) $this->is_internal,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
