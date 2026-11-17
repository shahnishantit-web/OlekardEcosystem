<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Local-only customer event catalogue fixtures. */
final class DevFiveOrganisationEventsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo event fixtures are local/testing only.');
        }
        $identity = DB::connection('identity');
        $events = DB::connection('community_services');
        $creator = $identity->table('identity.users')->where('email', 'event.creator@olekard.local')->value('id');
        $reviewer = $identity->table('identity.users')->where('email', 'event.reviewer@olekard.local')->value('id');
        $approver = $identity->table('identity.users')->where('email', 'event.approver@olekard.local')->value('id');
        $customer = $identity->table('identity.users')->where('mobile_number', '+26771000001')->value('id');
        if (! $creator || ! $reviewer || ! $approver || ! $customer) {
            throw new \RuntimeException('Seed DevEventWorkflowUsersSeeder and DevCustomerAppSeeder first.');
        }
        $now = now('Africa/Gaborone');
        $organisations = ['Gaborone Community Arts', 'Francistown Youth Network', 'Maun Cultural Association', 'Kanye Wellness Collective', 'Molepolole Sports Club'];
        foreach ($organisations as $index => $name) {
            $orgId = $this->id('org/'.$index);
            $identity->table('identity.organisations')->updateOrInsert(['id' => $orgId], [
                'registry_reference' => 'LOCAL-CUSTOMER-DEMO-'.($index + 1), 'display_name' => $name,
                'status' => 'active', 'evidence_reference' => 'local-demo://verified-organisation/'.$index,
                'requested_by' => $creator, 'reviewed_by' => $reviewer, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->authorityEvent($identity, 'organisation', $orgId, 'requested', $creator, null, 'pending_review');
            $this->authorityEvent($identity, 'organisation', $orgId, 'reviewed', $reviewer, 'pending_review', 'active');
            foreach (['creator' => $creator, 'reviewer' => $reviewer, 'approver' => $approver, 'customer' => $customer] as $role => $userId) {
                $membershipId = $this->id("membership/{$index}/{$role}");
                $identity->table('identity.organisation_memberships')->updateOrInsert(['id' => $membershipId], [
                    'organisation_id' => $orgId, 'user_id' => $userId, 'status' => 'active',
                    'evidence_reference' => 'local-demo://active-membership/'.$role,
                    'requested_by' => $creator, 'reviewed_by' => $reviewer, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->authorityEvent($identity, 'membership', $membershipId, 'requested', $creator, null, 'pending_review');
                $this->authorityEvent($identity, 'membership', $membershipId, 'reviewed', $reviewer, 'pending_review', 'active');
            }
            foreach ([1, 2] as $eventNumber) {
                $eventId = $this->id("event/{$index}/{$eventNumber}");
                $start = $now->copy()->addDays(14 + ($index * 3) + $eventNumber)->setTime(10, 0);
                $end = $start->copy()->addHours(5);
                $saleEnd = $start->copy()->subHour();
                $snapshot = ['id' => $eventId, 'title' => "{$name} Community Event {$eventNumber}", 'organiser_name' => $name,
                    'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'timezone' => 'Africa/Gaborone',
                    'capacity' => 100, 'event_format' => 'physical', 'venue_country_code' => 'BW', 'categories' => [[
                        'id' => $this->id("category/{$index}/{$eventNumber}"), 'name' => 'General Admission', 'capacity' => 100,
                        'price_minor' => 0, 'allocation_type' => 'free', 'max_per_person' => 2,
                    ]]];
                $events->table('community_services.event_drafts')->updateOrInsert(['id' => $eventId], [
                    'organisation_id' => $orgId, 'created_by' => $creator, 'title' => $snapshot['title'], 'organiser_name' => $name,
                    'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 100, 'currency_code' => null,
                    'timezone' => 'Africa/Gaborone', 'starts_at' => $start, 'ends_at' => $end,
                    'sales_start_at' => $now->copy()->subDay(), 'sales_end_at' => $saleEnd,
                    'cancellation_policy' => 'Local demo fixture.', 'transfer_policy' => 'Ticket transfers are disabled in the local demo.',
                    'entry_policy' => 'Show your ticket at the event entrance.', 'max_tickets_per_order' => 2,
                    'event_format' => 'physical', 'venue_country_code' => 'BW', 'workflow_state' => 'published',
                    'sales_state' => 'active', 'lifecycle_state' => 'active', 'version' => 1,
                    'reviewed_version' => 1, 'price_approved_version' => 1, 'published_version' => 1,
                    'event_reviewer_id' => $reviewer, 'price_approver_id' => $approver,
                    'published_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
                ]);
                $events->table('community_services.event_draft_categories')->updateOrInsert(['id' => $snapshot['categories'][0]['id']], [
                    'event_id' => $eventId, 'name' => 'General Admission', 'capacity' => 100, 'price_minor' => 0,
                    'allocation_type' => 'free', 'complimentary_quota' => null, 'max_per_person' => 2,
                    'issued_quantity' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $this->command?->info('Provisioned 5 local demo organisations, active memberships and 10 published free events.');
    }

    private function id(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/customer-event-demo/'.$name)->toString();
    }

    private function authorityEvent($identity, string $type, string $subjectId, string $action, string $actorId, ?string $previous, string $next): void
    {
        $identity->table('identity.organisation_authority_events')->insertOrIgnore([
            'id' => $this->id("authority/{$type}/{$subjectId}/{$action}"), 'subject_type' => $type,
            'subject_id' => $subjectId, 'action' => $action, 'actor_id' => $actorId,
            'previous_status' => $previous, 'new_status' => $next, 'created_at' => now(),
        ]);
    }
}
