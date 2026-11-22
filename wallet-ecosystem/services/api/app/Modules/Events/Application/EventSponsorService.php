<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/** Local POC sponsor catalogue, event association and privacy-safe sponsor report. */
final class EventSponsorService
{
    public function __construct(private readonly ScopedEventAuthorization $authorization) {}

    private function db(): Connection { return DB::connection('community_services'); }

    public function list(string $organisationId, string $actorId): array
    {
        $this->guardPoc();
        $this->authorization->assertOrganisation($actorId, $organisationId, EventPermissions::READ);
        return $this->db()->table('community_services.event_sponsors')->where('organisation_id', $organisationId)
            ->orderBy('name')->get(['id', 'name', 'description', 'logo_url', 'report_actor_id', 'active'])->map(static fn ($row) => (array) $row)->all();
    }

    public function create(string $organisationId, string $actorId, array $input): array
    {
        $this->guardPoc();
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:2000'],
            'logo_url' => ['nullable', 'url', 'max:2048'], 'report_actor_id' => ['nullable', 'uuid'],
        ])->validate();
        $id = (string) Uuid::uuid7();
        $this->db()->transaction(function () use ($id, $organisationId, $actorId, $data): void {
            $this->authorization->lockOrganisationAssignment($actorId, $organisationId, EventPermissions::UPDATE);
            if (($data['report_actor_id'] ?? null) !== null) {
                $memberIsActive = DB::connection('identity')->table('identity.organisation_memberships as membership')
                    ->join('identity.users as user', 'user.id', '=', 'membership.user_id')
                    ->where('membership.organisation_id', $organisationId)->where('membership.user_id', $data['report_actor_id'])
                    ->where('membership.status', 'active')->where('user.status', 'active')->exists();
                abort_unless($memberIsActive, 422, 'Sponsor report viewers must be active members of the event organisation.');
            }
            $this->db()->table('community_services.event_sponsors')->insert([
                'id' => $id, 'organisation_id' => $organisationId, 'name' => trim($data['name']),
                'description' => trim($data['description']), 'logo_url' => $data['logo_url'] ?? null,
                'report_actor_id' => $data['report_actor_id'] ?? null, 'active' => true,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($id, null, $actorId, 'sponsor_created');
        }, 3);
        return (array) $this->db()->table('community_services.event_sponsors')->where('id', $id)->first();
    }

    public function link(string $eventId, string $sponsorId, string $actorId): void
    {
        $this->guardPoc();
        $this->db()->transaction(function () use ($eventId, $sponsorId, $actorId): void {
            $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::UPDATE);
            abort_unless($this->db()->table('community_services.event_sponsors')->where('id', $sponsorId)
                ->where('organisation_id', $event->organisation_id)->where('active', true)->exists(), 404, 'Active sponsor was not found.');
            $exists = $this->db()->table('community_services.event_sponsor_links')->where('event_id', $eventId)->where('sponsor_id', $sponsorId)->exists();
            $this->db()->table('community_services.event_sponsor_links')->insertOrIgnore([
                'event_id' => $eventId, 'sponsor_id' => $sponsorId, 'created_at' => now(),
            ]);
            if (! $exists) $this->audit($sponsorId, $eventId, $actorId, 'sponsor_linked');
        }, 3);
    }

    public function publicForEvent(string $eventId): array
    {
        if (config('events.poc_enabled') !== true || config('events.poc_demo_sponsor_enabled') !== true || ! app()->environment('local')) return [];
        return $this->db()->table('community_services.event_sponsors as s')
            ->join('community_services.event_sponsor_links as l', 'l.sponsor_id', '=', 's.id')
            ->where('l.event_id', $eventId)->where('s.active', true)
            ->orderBy('s.name')->get(['s.name', 's.description', 's.logo_url'])->map(static fn ($row) => (array) $row)->all();
    }

    public function report(string $eventId, string $actorId): array
    {
        $this->guardPoc();
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)
            ->where('workflow_state', 'published')->first();
        abort_if($event === null, 404, 'Published event was not found.');
        abort_unless(DB::connection('identity')->table('identity.organisation_memberships as membership')
            ->join('identity.users as user', 'user.id', '=', 'membership.user_id')
            ->where('membership.organisation_id', $event->organisation_id)->where('membership.user_id', $actorId)
            ->where('membership.status', 'active')->where('user.status', 'active')->exists(),
            403, 'An active membership in the event organisation is required for sponsor reporting.');
        abort_unless($this->db()->table('community_services.event_sponsors as s')
            ->join('community_services.event_sponsor_links as l', 'l.sponsor_id', '=', 's.id')
            ->where('l.event_id', $eventId)->where('s.report_actor_id', $actorId)->where('s.active', true)->exists(),
            403, 'This account is not an assigned sponsor report viewer for the event.');
        $noShowsFinalized = now()->greaterThanOrEqualTo($event->ends_at);
        $rows = $this->db()->table('community_services.event_draft_categories as c')->where('c.event_id', $eventId)
            ->select('c.name')
            ->selectSub(fn ($q) => $q->from('community_services.event_free_registrations as r')->selectRaw('COUNT(*)')
                ->whereColumn('r.category_id', 'c.id')->where('r.event_id', $eventId)->where('r.status', 'registered'), 'free_registrations')
            ->selectSub(fn ($q) => $q->from('community_services.event_tickets as t')->selectRaw('COUNT(*)')
                ->whereColumn('t.category_id', 'c.id')->where('t.event_id', $eventId)->where('t.entitlement_source', 'paid')->where('t.status', 'valid'), 'paid_sales')
            ->selectSub(fn ($q) => $q->from('community_services.event_attendance as a')->selectRaw('COUNT(*)')
                ->whereColumn('a.category_id', 'c.id')->where('a.event_id', $eventId), 'admissions')->orderBy('c.name')->get();
        return ['event_title' => $event->title, 'event_starts_at' => $event->starts_at,
            'no_shows_finalized' => $noShowsFinalized,
            'categories' => $rows->map(static fn ($row) => ['category' => $row->name,
                'free_registrations' => (int) $row->free_registrations, 'paid_sales' => (int) $row->paid_sales,
                'admissions' => (int) $row->admissions])->all()];
    }

    private function guardPoc(): void
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true
            && config('events.poc_demo_sponsor_enabled') === true, 404, 'POC sponsor capability is unavailable.');
    }

    private function audit(string $sponsorId, ?string $eventId, string $actorId, string $action): void
    {
        $this->db()->table('community_services.event_sponsor_audit')->insert([
            'id' => (string) Uuid::uuid7(), 'sponsor_id' => $sponsorId, 'event_id' => $eventId,
            'actor_id' => $actorId, 'action' => $action, 'created_at' => now(),
        ]);
    }
}
