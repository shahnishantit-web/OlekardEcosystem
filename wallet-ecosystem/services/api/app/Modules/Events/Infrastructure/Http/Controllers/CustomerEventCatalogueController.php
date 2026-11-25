<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventSponsorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerEventCatalogueController extends Controller
{
    public function __construct(private readonly EventSponsorService $sponsors) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER', 403,
            'Customer authentication is required.');
        $now = now();
        $events = DB::connection('community_services')->table('community_services.event_drafts')
            ->where('workflow_state', 'published')->where('sales_state', 'active')->where('lifecycle_state', 'active')
            ->where('starts_at', '>', $now)->where('sales_end_at', '>', $now)
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
                'id' => $event->id, 'title' => $event->title, 'image_url' => $event->image_url,
                'organiser_name' => $event->organiser_name,
                'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'timezone' => $event->timezone,
                'sales_start_at' => $event->sales_start_at, 'sales_end_at' => $event->sales_end_at,
                'booking_status' => now()->lt($event->sales_start_at) ? 'upcoming' : 'open',
                'event_format' => $event->event_format, 'venue_country_code' => $event->venue_country_code,
                'venue_name' => $event->venue_name, 'venue_city' => $event->venue_city ?? null,
                'venue_address' => $event->venue_address,
                'currency_code' => $event->currency_code,
                'currency_precision' => (int) ($event->currency_precision_snapshot ?? 2),
                'max_tickets_per_order' => $event->max_tickets_per_order,
                'sponsors' => $this->sponsors->publicForEvent((string) $event->id),
                'categories' => $categories,
            ];
        })->map(static fn (array $event): array => [...$event, 'categories' => $event['categories']->values()])->values();
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function invitations(Request $request): JsonResponse
    {
        abort_unless(strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER', 403,
            'Customer authentication is required.');
        $actorId = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actorId), 401, 'Authenticated customer context is required.');
        $now = now();
        $rows = DB::connection('community_services')->table('community_services.event_invitations as i')
            ->join('community_services.event_drafts as e', 'e.id', '=', 'i.event_id')
            ->join('community_services.event_draft_categories as c', 'c.id', '=', 'i.category_id')
            ->where('i.recipient_actor_id', $actorId)
            ->where('i.status', 'active')->whereNull('i.revoked_at')
            ->where('i.valid_from', '<=', $now)->where('i.expires_at', '>', $now)
            ->whereColumn('i.uses_count', '<', 'i.max_uses')
            ->where('e.workflow_state', 'published')->whereColumn('e.published_version', 'e.version')
            ->where('e.lifecycle_state', 'active')->where('e.starts_at', '>', $now)
            ->where('e.sales_state', 'active')->where('e.sales_end_at', '>', $now)
            ->orderBy('e.starts_at')
            ->get([
                'i.id as invitation_id', 'i.expires_at', 'i.max_uses', 'i.uses_count',
                'e.id as event_id', 'e.title as event_title', 'e.image_url', 'e.starts_at', 'e.ends_at',
                'e.sales_start_at', 'e.venue_name', 'e.venue_city', 'e.venue_address', 'e.organiser_name',
                'e.currency_code', 'e.currency_precision_snapshot', 'c.id as category_id',
                'c.name as category_name', 'c.price_minor', 'c.allocation_type',
            ])
            ->map(static fn (object $row): array => [
                'invitation_id' => (string) $row->invitation_id,
                'expires_at' => (string) $row->expires_at,
                'remaining_uses' => max(0, (int) $row->max_uses - (int) $row->uses_count),
                'event_id' => (string) $row->event_id,
                'event_title' => (string) $row->event_title,
                'image_url' => $row->image_url === null ? null : (string) $row->image_url,
                'starts_at' => (string) $row->starts_at,
                'ends_at' => (string) $row->ends_at,
                'sales_start_at' => (string) $row->sales_start_at,
                'venue_name' => $row->venue_name === null ? null : (string) $row->venue_name,
                'venue_city' => $row->venue_city === null ? null : (string) $row->venue_city,
                'venue_address' => $row->venue_address === null ? null : (string) $row->venue_address,
                'organiser_name' => (string) $row->organiser_name,
                'currency_code' => $row->currency_code === null ? null : (string) $row->currency_code,
                'currency_precision' => (int) ($row->currency_precision_snapshot ?? 2),
                'category_id' => (string) $row->category_id,
                'category_name' => (string) $row->category_name,
                'price_minor' => (int) $row->price_minor,
                'allocation_type' => (string) $row->allocation_type,
                'booking_status' => now()->lt($row->sales_start_at) ? 'upcoming' : 'open',
            ])->values();

        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
