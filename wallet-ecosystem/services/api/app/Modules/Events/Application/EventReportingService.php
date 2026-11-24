<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Aggregate-only event reporting. Attendee and ticket identifiers are never returned. */
final class EventReportingService
{
    public function __construct(private readonly ScopedEventAuthorization $authorization) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @return array<string, mixed> */
    public function forOrganiser(string $eventId, string $actorId): array
    {
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent(
            $actorId,
            (string) $event->organisation_id,
            $eventId,
            EventPermissions::ATTENDANCE_READ,
        );

        return $this->aggregate($event, app(EventAdmissionService::class)->aggregateAttendance($eventId, $actorId));
    }

    /** @return array<string, mixed> */
    public function aggregate(object $event, array $attendanceRows = []): array
    {
        $eventId = (string) $event->id;
        $noShowsByCategory = collect($attendanceRows)->keyBy('category');
        $categories = $this->db()->table('community_services.event_draft_categories as c')
            ->where('c.event_id', $eventId)->orderBy('c.name')->get([
                'c.id', 'c.name', 'c.capacity', 'c.allocation_type',
            ])->map(function (object $category) use ($eventId, $noShowsByCategory): array {
                $paidSales = (int) $this->db()->table('community_services.event_tickets as t')
                    ->join('community_services.event_orders as o', 'o.id', '=', 't.order_id')
                    ->where('t.event_id', $eventId)->where('t.category_id', $category->id)
                    ->where('t.entitlement_source', 'paid')->where('t.status', 'valid')
                    ->where('o.order_state', 'issued')->where('o.payment_state', 'confirmed')
                    ->where('o.accounting_state', 'verified')->where('o.fulfilment_state', 'issued')->count();
                $free = (int) $this->db()->table('community_services.event_free_registrations')
                    ->where('event_id', $eventId)->where('category_id', $category->id)
                    ->where('entitlement_source', 'free')->where('status', 'registered')->count();
                $complimentary = (int) $this->db()->table('community_services.event_free_registrations')
                    ->where('event_id', $eventId)->where('category_id', $category->id)
                    ->where('entitlement_source', 'complimentary')->where('status', 'registered')->count();
                $activeHolds = (int) $this->db()->table('community_services.event_inventory_holds')
                    ->where('event_id', $eventId)->where('category_id', $category->id)
                    ->where('status', 'held')->where('expires_at', '>', now())->sum('quantity');
                $admissions = (int) $this->db()->table('community_services.event_attendance')
                    ->where('event_id', $eventId)->where('category_id', $category->id)->count();
                $issued = $paidSales + $free + $complimentary;
                $capacity = (int) $category->capacity;
                $noShowRow = $noShowsByCategory->get((string) $category->name);

                return [
                    'category' => (string) $category->name,
                    'allocation_type' => (string) $category->allocation_type,
                    'capacity' => $capacity,
                    'tickets_issued' => $issued,
                    'paid_sales' => $paidSales,
                    'free_registrations' => $free,
                    'complimentary_allocations_used' => $complimentary,
                    'active_holds' => $activeHolds,
                    'remaining_capacity' => max(0, $capacity - $issued - $activeHolds),
                    'admissions' => $admissions,
                    'no_shows' => $noShowRow['no_shows'] ?? null,
                    'no_shows_finalized' => (bool) ($noShowRow['no_shows_finalized'] ?? false),
                ];
            })->all();

        $totals = [
            'category_capacity' => 0,
            'tickets_issued' => 0,
            'paid_sales' => 0,
            'free_registrations' => 0,
            'complimentary_allocations_used' => 0,
            'active_holds' => 0,
            'remaining_capacity' => 0,
            'admissions' => 0,
            'no_shows' => null,
            'no_shows_finalized' => now()->greaterThanOrEqualTo($event->ends_at) && $attendanceRows !== [],
        ];
        foreach ($categories as $category) {
            foreach (['capacity', 'tickets_issued', 'paid_sales', 'free_registrations',
                'complimentary_allocations_used', 'active_holds', 'remaining_capacity', 'admissions'] as $key) {
                $target = $key === 'capacity' ? 'category_capacity' : $key;
                $totals[$target] += $category[$key];
            }
            if ($totals['no_shows_finalized']) {
                $totals['no_shows'] += (int) ($category['no_shows'] ?? 0);
            }
        }

        return [
            'event_id' => $eventId,
            'event_title' => (string) $event->title,
            'event_starts_at' => (string) $event->starts_at,
            'event_ends_at' => (string) $event->ends_at,
            'event_status' => (string) ($event->lifecycle_state ?? 'active'),
            'event_capacity' => (int) $event->capacity,
            'totals' => $totals,
            'categories' => $categories,
            'privacy' => 'Aggregate report; attendee and ticket identifiers are excluded.',
        ];
    }

    /** @param array<string, mixed> $report */
    public function csv(array $report): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to prepare event report export.');
        }
        fputcsv($stream, ['Event', $report['event_title']]);
        fputcsv($stream, ['Starts at', $report['event_starts_at']]);
        fputcsv($stream, ['Ends at', $report['event_ends_at']]);
        fputcsv($stream, ['Event capacity', $report['event_capacity']]);
        fputcsv($stream, []);
        fputcsv($stream, ['Category', 'Allocation type', 'Capacity', 'Tickets issued', 'Paid sales',
            'Free registrations', 'Complimentary used', 'Active holds', 'Remaining capacity',
            'Admissions', 'No-shows', 'No-shows finalized']);
        foreach ($report['categories'] as $category) {
            fputcsv($stream, [
                $category['category'], $category['allocation_type'], $category['capacity'],
                $category['tickets_issued'], $category['paid_sales'], $category['free_registrations'],
                $category['complimentary_allocations_used'], $category['active_holds'],
                $category['remaining_capacity'], $category['admissions'], $category['no_shows'] ?? '',
                $category['no_shows_finalized'] ? 'yes' : 'no',
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if (! is_string($csv)) {
            throw new \RuntimeException('Unable to read event report export.');
        }

        return $csv;
    }
}
