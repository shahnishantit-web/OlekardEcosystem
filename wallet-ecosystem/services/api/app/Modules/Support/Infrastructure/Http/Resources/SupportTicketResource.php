<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasPermission('support.assign') ?? false;

        $notes = null;
        if ($this->relationLoaded('notes')) {
            $noteCollection = $isAdmin
                ? $this->notes
                : $this->notes->where('is_internal', false)->values();

            $notes = TicketNoteResource::collection($noteCollection)->resolve();
        }

        return [
            'id'              => $this->id,
            'ticket_number'   => $this->ticket_number,
            'subject'         => $this->subject,
            'description'     => $this->description,
            'category'        => $this->category instanceof \BackedEnum ? $this->category->value : $this->category,
            'priority'        => $this->priority instanceof \BackedEnum ? $this->priority->value : $this->priority,
            'status'          => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'reporter_id'     => $this->reporter_id,
            'reporter_type'   => $this->reporter_type,
            'assignee_id'     => $this->assignee_id,
            'dispute_id'      => $this->dispute_id,
            'resolution_note' => $this->resolution_note,
            'resolved_at'     => $this->resolved_at?->toIso8601String(),
            'closed_at'       => $this->closed_at?->toIso8601String(),
            'notes'           => $notes,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
