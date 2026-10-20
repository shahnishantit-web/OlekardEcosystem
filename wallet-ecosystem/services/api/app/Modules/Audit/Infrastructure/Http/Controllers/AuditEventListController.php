<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Infrastructure\Http\Requests\ListAuditEventsRequest;
use App\Modules\Audit\Infrastructure\Http\Resources\AuditEventSummaryResource;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;

final class AuditEventListController extends Controller
{
    public function __invoke(ListAuditEventsRequest $request): ResourceCollection
    {

        $perPage = $request->perPage();
        $query   = AuditEventModel::query()->orderByDesc('occurred_at')->orderByDesc('id');

        if ($category = $request->input('category')) {
            $query->where('event_category', $category);
        }
        if ($actorId = $request->input('actor_id')) {
            $query->where('actor_id', $actorId);
        }
        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId = $request->input('entity_id')) {
            $query->where('entity_id', $entityId);
        }
        if ($outcome = $request->input('outcome')) {
            $query->where('outcome', $outcome);
        }
        if ($correlationId = $request->input('correlation_id')) {
            $query->where('correlation_id', $correlationId);
        }
        if ($from = $request->input('from')) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('occurred_at', '<=', $to);
        }

        if ($cursor = $request->input('cursor')) {
            [$cursorOccurredAt, $cursorId] = $this->decodeCursor($cursor);
            if ($cursorOccurredAt !== null && $cursorId !== null) {
                $query->where(function ($q) use ($cursorOccurredAt, $cursorId): void {
                    $q->where('occurred_at', '<', $cursorOccurredAt)
                      ->orWhere(function ($q2) use ($cursorOccurredAt, $cursorId): void {
                          $q2->where('occurred_at', '=', $cursorOccurredAt)
                             ->where('id', '<', $cursorId);
                      });
                });
            }
        }

        $items   = $query->limit($perPage + 1)->get();
        $hasMore = $items->count() > $perPage;
        $items   = $items->take($perPage);

        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last       = $items->last();
            $nextCursor = $this->encodeCursor($last->occurred_at->toIso8601String(), $last->id);
        }

        return AuditEventSummaryResource::collection($items)
            ->additional(['meta' => ['next_cursor' => $nextCursor]]);
    }

    private function encodeCursor(string $occurredAt, string $id): string
    {
        return base64_encode($occurredAt.'|'.$id);
    }

    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode($cursor, strict: true);
        if ($decoded === false) {
            return [null, null];
        }
        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }
        return [$parts[0], $parts[1]];
    }
}
