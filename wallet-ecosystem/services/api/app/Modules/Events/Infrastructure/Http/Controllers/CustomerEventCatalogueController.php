<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class CustomerEventCatalogueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER', 403,
            'Customer authentication is required.');
        $now = now();
        $events = DB::connection('community_services')->table('community_services.event_drafts')
            ->where('workflow_state', 'published')->where('sales_state', 'active')->where('lifecycle_state', 'active')
            ->where('starts_at', '>', $now)->where('sales_start_at', '<=', $now)->where('sales_end_at', '>', $now)
            ->whereColumn('published_version', 'version')->orderBy('starts_at')->get();
        $data = $events->map(function ($event): array {
            $categories = DB::connection('community_services')->table('community_services.event_draft_categories')
                ->where('event_id', $event->id)
                ->where(function ($query): void {
                    $query->where(function ($free): void {
                        $free->whereIn('allocation_type', ['free', 'complimentary'])->where('price_minor', 0);
                    })->orWhere(function ($paid): void {
                        $paid->where('allocation_type', 'paid')->where('price_minor', '>', 0);
                    });
                })
                ->get(['id', 'name', 'capacity', 'price_minor', 'max_per_person', 'allocation_type']);
            return [
                'id' => $event->id, 'title' => $event->title, 'organiser_name' => $event->organiser_name,
                'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'timezone' => $event->timezone,
                'event_format' => $event->event_format, 'venue_country_code' => $event->venue_country_code,
                'currency_code' => $event->currency_code,
                'currency_precision' => (int) ($event->currency_precision_snapshot ?? 2),
                'max_tickets_per_order' => $event->max_tickets_per_order,
                'categories' => $categories,
            ];
        })->filter(static fn (array $event): bool => $event['categories']->isNotEmpty())
          ->map(static fn (array $event): array => [...$event, 'categories' => $event['categories']->values()])->values();
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
