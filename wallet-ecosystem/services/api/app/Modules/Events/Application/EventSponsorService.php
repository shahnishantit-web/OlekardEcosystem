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
    public function __construct(
        private readonly ScopedEventAuthorization $authorization,
        private readonly EventReportingService $reporting,
    ) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    public function list(string $organisationId, string $actorId): array
    {
        $this->guardPoc();
        $this->authorization->assertOrganisation($actorId, $organisationId, EventPermissions::READ);

        return $this->db()->table('community_services.event_sponsors')->where('organisation_id', $organisationId)
            ->orderBy('name')->get(['id', 'name', 'description', 'logo_url', 'report_actor_id', 'active'])->map(static fn ($row) => (array) $row)->all();
    }

    /** @return list<array{id:string,display_name:string,email:?string,mobile_number:?string}> */
    public function reportViewers(string $organisationId, string $actorId): array
    {
        $this->guardPoc();
        $this->authorization->assertOrganisation($actorId, $organisationId, EventPermissions::UPDATE);
        $users = DB::connection('identity')->table('identity.organisation_memberships as membership')
            ->join('identity.users as user', 'user.id', '=', 'membership.user_id')
            ->where('membership.organisation_id', $organisationId)->where('membership.status', 'active')
            ->where('user.status', 'active')->orderBy('user.email')->get(['user.id', 'user.email', 'user.mobile_number']);

        return $users->map(static function (object $user): array {
            $email = $user->email === null ? null : (string) $user->email;
            $mobile = $user->mobile_number === null ? null : (string) $user->mobile_number;
            $display = $email ?? $mobile ?? 'Organisation member';

            return ['id' => (string) $user->id, 'display_name' => $display, 'email' => $email, 'mobile_number' => $mobile];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    public function listForEvent(string $eventId, string $actorId): array
    {
        $this->guardPoc();
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::READ);

        return $this->db()->table('community_services.event_sponsor_links as l')
            ->join('community_services.event_sponsors as s', 's.id', '=', 'l.sponsor_id')
            ->where('l.event_id', $eventId)->orderBy('s.name')
            ->get(['s.id', 's.name', 's.description', 's.logo_url', 's.report_actor_id', 's.active', 'l.created_at as linked_at'])
            ->map(static fn ($row) => (array) $row)->all();
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
            if (! $exists) {
                $this->audit($sponsorId, $eventId, $actorId, 'sponsor_linked');
            }
        }, 3);
    }

    public function unlink(string $eventId, string $sponsorId, string $actorId): void
    {
        $this->guardPoc();
        $this->db()->transaction(function () use ($eventId, $sponsorId, $actorId): void {
            $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::UPDATE);
            $deleted = $this->db()->table('community_services.event_sponsor_links')
                ->where('event_id', $eventId)->where('sponsor_id', $sponsorId)->delete();
            abort_unless($deleted > 0, 404, 'Sponsor link was not found.');
            $this->audit($sponsorId, $eventId, $actorId, 'sponsor_unlinked');
        }, 3);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(string $organisationId, string $sponsorId, string $actorId, array $input): array
    {
        $this->guardPoc();
        $data = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'report_actor_id' => ['sometimes', 'nullable', 'uuid'],
            'active' => ['sometimes', 'required', 'boolean'],
        ])->validate();
        abort_if($data === [], 422, 'Provide at least one sponsor field to update.');

        $this->db()->transaction(function () use ($data, $organisationId, $sponsorId, $actorId): void {
            $this->authorization->lockOrganisationAssignment($actorId, $organisationId, EventPermissions::UPDATE);
            $sponsor = $this->db()->table('community_services.event_sponsors')
                ->where('id', $sponsorId)->where('organisation_id', $organisationId)->lockForUpdate()->first();
            abort_if($sponsor === null, 404, 'Sponsor was not found.');
            if (array_key_exists('report_actor_id', $data) && $data['report_actor_id'] !== null) {
                $memberIsActive = DB::connection('identity')->table('identity.organisation_memberships as membership')
                    ->join('identity.users as user', 'user.id', '=', 'membership.user_id')
                    ->where('membership.organisation_id', $organisationId)->where('membership.user_id', $data['report_actor_id'])
                    ->where('membership.status', 'active')->where('user.status', 'active')->exists();
                abort_unless($memberIsActive, 422, 'Sponsor report viewers must be active members of the event organisation.');
            }
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
            }
            if (isset($data['description'])) {
                $data['description'] = trim($data['description']);
            }
            $this->db()->table('community_services.event_sponsors')->where('id', $sponsorId)
                ->where('organisation_id', $organisationId)->update($data + ['updated_at' => now()]);
            $action = array_key_exists('active', $data) && ! $data['active'] ? 'sponsor_deactivated' : 'sponsor_updated';
            $this->audit($sponsorId, null, $actorId, $action);
        }, 3);

        return (array) $this->db()->table('community_services.event_sponsors')->where('id', $sponsorId)->first();
    }

    public function publicForEvent(string $eventId): array
    {
        if (config('events.poc_enabled') !== true || config('events.poc_demo_sponsor_enabled') !== true || ! app()->environment('local')) {
            return [];
        }
        $rows = $this->db()->table('community_services.event_sponsors as s')
            ->join('community_services.event_sponsor_links as l', 'l.sponsor_id', '=', 's.id')
            ->where('l.event_id', $eventId)->where('s.active', true)
            ->orderBy('s.name')->get(['s.name', 's.description', 's.logo_url'])->map(static fn ($row) => (array) $row)->all();

        return $rows;
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
        $sponsorNames = $this->db()->table('community_services.event_sponsors as s')
            ->join('community_services.event_sponsor_links as l', 'l.sponsor_id', '=', 's.id')
            ->where('l.event_id', $eventId)->where('s.report_actor_id', $actorId)->where('s.active', true)
            ->orderBy('s.name')->pluck('s.name')->map(static fn ($name): string => (string) $name)->all();

        return ['sponsors' => $sponsorNames, ...$this->reporting->aggregate($event)];
    }

    public function reportCsv(string $eventId, string $actorId): string
    {
        return $this->reporting->csv($this->report($eventId, $actorId));
    }

    /** @return list<array{event_id:string,event_title:string,event_starts_at:string,organisation_name:string,sponsors:list<string>}> */
    public function reportsForActor(string $actorId): array
    {
        $this->guardPoc();

        return $this->db()->table('community_services.event_sponsors as s')
            ->join('community_services.event_sponsor_links as l', 'l.sponsor_id', '=', 's.id')
            ->join('community_services.event_drafts as e', 'e.id', '=', 'l.event_id')
            ->join('identity.organisation_memberships as m', function ($join): void {
                $join->on('m.organisation_id', '=', 's.organisation_id')->on('m.user_id', '=', 's.report_actor_id');
            })
            ->join('identity.users as u', 'u.id', '=', 'm.user_id')
            ->join('identity.organisations as o', 'o.id', '=', 's.organisation_id')
            ->where('s.report_actor_id', $actorId)->where('s.active', true)
            ->where('m.status', 'active')->where('u.status', 'active')->where('e.workflow_state', 'published')
            ->select('e.id as event_id', 'e.title as event_title', 'e.starts_at as event_starts_at',
                'o.display_name as organisation_name', 's.name as sponsor_name')
            ->orderBy('e.starts_at')->orderBy('e.title')->orderBy('s.name')->get();
        $events = [];
        foreach ($rows as $row) {
            $id = (string) $row->event_id;
            $events[$id] ??= [
                'event_id' => $id,
                'event_title' => (string) $row->event_title,
                'event_starts_at' => (string) $row->event_starts_at,
                'organisation_name' => (string) $row->organisation_name,
                'sponsors' => [],
            ];
            $events[$id]['sponsors'][] = (string) $row->sponsor_name;
        }

        return array_values($events);
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
