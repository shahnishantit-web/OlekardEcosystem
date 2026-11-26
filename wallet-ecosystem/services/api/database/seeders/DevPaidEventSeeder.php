<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Local-only published paid event catalogue fixtures. */
final class DevPaidEventSeeder extends Seeder
{
    private const ORGANISATIONS = [
        'Gaborone Community Arts',
        'Francistown Youth Network',
        'Maun Cultural Association',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Paid event demo fixtures are local/testing only.');
        }

        $identity = DB::connection('identity');
        $events = DB::connection('community_services');
        $creatorId = $identity->table('identity.users')->where('email', 'event.creator@olekard.local')->value('id');
        $reviewerId = $identity->table('identity.users')->where('email', 'event.reviewer@olekard.local')->value('id');
        $approverId = $identity->table('identity.users')->where('email', 'event.approver@olekard.local')->value('id');
        if (! $creatorId || ! $reviewerId || ! $approverId) {
            throw new \RuntimeException('Run DevEventWorkflowUsersSeeder and DevFiveOrganisationEventsSeeder first.');
        }

        $now = now('Africa/Gaborone');
        foreach (self::ORGANISATIONS as $index => $name) {
            $organisationId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/customer-event-demo/org/'.$index)->toString();
            if (! $identity->table('identity.organisations')->where('id', $organisationId)->exists()) {
                throw new \RuntimeException('Run DevFiveOrganisationEventsSeeder before DevPaidEventSeeder.');
            }

            $eventId = $this->id('event/'.$index);
            $categoryId = $this->id('category/'.$index);
            $assignmentId = $this->id('currency-assignment/'.$index);
            $title = $name.' Paid Ticket Demo';
            $startsAt = $now->copy()->addDays(21 + $index)->setTime(18, 0);
            $endsAt = $startsAt->copy()->addHours(4);
            $salesStartAt = $now->copy()->subDay();
            $salesEndAt = $startsAt->copy()->subHours(2);
            $city = ['Gaborone', 'Francistown', 'Maun'][$index];
            $description = "{$title} is a local OleKard POC event for demonstrating paid ticket selection and checkout, hosted by {$name}. The event programme and exact venue details are demo placeholders and will be confirmed by the organiser.";
            $venueName = 'Venue details to be confirmed';
            $venueAddress = 'The organiser will share the confirmed venue address and directions before the event.';
            $priceMinor = [2500, 5000, 7500][$index];
            $category = [
                'id' => $categoryId,
                'name' => 'General Admission',
                'capacity' => 100,
                'price_minor' => $priceMinor,
                'allocation_type' => 'paid',
                'max_per_person' => null,
            ];
            $snapshot = [
                'id' => $eventId,
                'title' => $title,
                'organiser_name' => $name,
                'description' => $description,
                'event_category' => 'other',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
                'timezone' => 'Africa/Gaborone',
                'capacity' => 100,
                'event_format' => 'physical',
                'venue_country_code' => 'BW',
                'venue_name' => $venueName,
                'venue_city' => $city,
                'venue_address' => $venueAddress,
                'currency_code' => 'BWP',
                'currency_precision' => 2,
                'categories' => [$category],
            ];

            $events->table('community_services.event_drafts')->updateOrInsert(['id' => $eventId], [
                'organisation_id' => $organisationId,
                'created_by' => $creatorId,
                'title' => $title,
                'organiser_name' => $name,
                'description' => $description,
                'event_category' => 'other',
                'sponsors' => json_encode([], JSON_THROW_ON_ERROR),
                'capacity' => 100,
                'currency_code' => 'BWP',
                'currency_precision_snapshot' => 2,
                'currency_assignment_id' => $assignmentId,
                'currency_definition_updated_at' => $now,
                'timezone' => 'Africa/Gaborone',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'sales_start_at' => $salesStartAt,
                'sales_end_at' => $salesEndAt,
                'cancellation_policy' => 'Local demo fixture; cancellation handling is not configured.',
                'transfer_policy' => 'Ticket transfers are disabled in the local demo.',
                'entry_policy' => 'Show the purchased ticket QR code at the event entrance.',
                'max_tickets_per_order' => 4,
                'hold_duration_seconds' => 600,
                'event_format' => 'physical',
                'venue_country_code' => 'BW',
                'venue_name' => $venueName,
                'venue_city' => $city,
                'venue_address' => $venueAddress,
                'workflow_state' => 'published',
                'sales_state' => 'active',
                'lifecycle_state' => 'active',
                'version' => 1,
                'reviewed_version' => 1,
                'price_approved_version' => 1,
                'published_version' => 1,
                'event_reviewer_id' => $reviewerId,
                'price_approver_id' => $approverId,
                'published_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $events->table('community_services.event_draft_categories')->updateOrInsert(['id' => $categoryId], [
                'event_id' => $eventId,
                'name' => $category['name'],
                'capacity' => $category['capacity'],
                'price_minor' => $priceMinor,
                'allocation_type' => 'paid',
                'complimentary_quota' => null,
                'max_per_person' => null,
                'issued_quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $events->table('community_services.event_currency_assignments')->updateOrInsert(['id' => $assignmentId], [
                'event_id' => $eventId,
                'organisation_id' => $organisationId,
                'currency_code' => 'BWP',
                'precision_snapshot' => 2,
                'currency_definition_updated_at' => $now,
                'assigned_by_actor_id' => $approverId,
                'approval_reference' => 'local-demo://currency-assignment/'.$index,
                'policy_version' => 'local-demo-bwp-v1',
                'assigned_at' => $now,
                'superseded_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->ensureAuthorityAssignments($events, $organisationId, $eventId, $creatorId, $reviewerId, $approverId);
            if ($index === 0) $this->ensurePocSponsor($events, $organisationId, $eventId, $creatorId, $approverId);
        }

        if (config('events.local_demo_pricing_enabled') === true) {
            $this->enableLocalMockBankPayment($now);
        }

        $this->command?->info('Provisioned 3 local published paid-event catalogue fixtures (BWP 25.00, 50.00 and 75.00).');
        if (config('events.local_demo_pricing_enabled') !== true) {
            $this->command?->warn('Set EVENTS_LOCAL_DEMO_PRICING_ENABLED=true in local .env and rerun this seeder to enable demo checkout.');
        } else {
            $this->command?->warn('Local demo checkout uses test-only zero-fee pricing and the development mock bank. No real payment is processed.');
        }
    }

    private function ensureAuthorityAssignments($events, string $organisationId, string $eventId, string $creatorId,
        string $reviewerId, string $approverId): void
    {
        $assignments = [
            ['organisation', $organisationId, $organisationId, null, $creatorId, EventPermissions::READ],
            ['organisation', $organisationId, $organisationId, null, $creatorId, EventPermissions::UPDATE],
            ['event', $eventId, $organisationId, $eventId, $creatorId, EventPermissions::READ],
            ['event', $eventId, $organisationId, $eventId, $creatorId, EventPermissions::UPDATE],
            ['event', $eventId, $organisationId, $eventId, $creatorId, EventPermissions::CANCEL_REQUEST],
            ['event', $eventId, $organisationId, $eventId, $creatorId, EventPermissions::PAYOUT_REQUEST],
            ['event', $eventId, $organisationId, $eventId, $creatorId, EventPermissions::ATTENDANCE_READ],
            ['event', $eventId, $organisationId, $eventId, $reviewerId, EventPermissions::READ],
            ['event', $eventId, $organisationId, $eventId, $reviewerId, EventPermissions::CANCEL_APPROVE],
            ['event', $eventId, $organisationId, $eventId, $reviewerId, EventPermissions::ATTENDANCE_READ],
            ['event', $eventId, $organisationId, $eventId, $approverId, EventPermissions::READ],
            ['event', $eventId, $organisationId, $eventId, $approverId, EventPermissions::PAYOUT_APPROVE],
            ['event', $eventId, $organisationId, $eventId, $approverId, EventPermissions::ATTENDANCE_READ],
        ];
        foreach ($assignments as [$scopeType, $scopeId, $orgId, $assignedEventId, $actorId, $permission]) {
            $existing = $events->table('community_services.event_authority_assignments')
                ->where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('actor_id', $actorId)
                ->where('permission_slug', $permission)->whereNull('revoked_at')->exists();
            if ($existing) continue;
            $assignmentId = $this->id('assignment/'.$scopeType.'/'.$scopeId.'/'.$actorId.'/'.$permission);
            $events->table('community_services.event_authority_assignments')->insert([
                'id' => $assignmentId, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'organisation_id' => $orgId, 'event_id' => $assignedEventId, 'gate_id' => null,
                'actor_id' => $actorId, 'permission_slug' => $permission, 'granted_by_actor_id' => $creatorId,
                'granted_at' => now(), 'revoked_by_actor_id' => null, 'revoked_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $events->table('community_services.event_authority_assignment_audit')->insertOrIgnore([
                'id' => $this->id('assignment-audit/'.$scopeType.'/'.$scopeId.'/'.$actorId.'/'.$permission),
                'assignment_id' => $assignmentId, 'action' => 'granted', 'actor_id' => $creatorId,
                'target_actor_id' => $actorId, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'permission_slug' => $permission, 'reason_code' => 'local_demo_seed',
                'correlation_id' => $this->id('assignment-correlation/'.$scopeType.'/'.$scopeId.'/'.$actorId.'/'.$permission),
                'created_at' => now(),
            ]);
        }
    }

    private function enableLocalMockBankPayment(\Illuminate\Support\Carbon $now): void
    {
        if (! app()->environment('local') || config('events.local_demo_pricing_enabled') !== true) {
            return;
        }

        $payment = DB::connection('payment');
        $provider = $payment->table('payment.payment_providers')->where('code', 'mock_bank')->first();
        $providerId = $provider?->id ?? $this->id('mock-bank-provider');
        $payment->table('payment.payment_providers')->updateOrInsert(['id' => $providerId], [
            'code' => 'mock_bank',
            'display_name' => 'Development Mock Bank (local only)',
            'adapter_identifier' => 'mock_bank',
            'adapter_version' => '1.0.0',
            'environment' => 'mock',
            'is_enabled' => true,
            'is_suspended' => false,
            'is_default' => false,
            'sort_order' => 10,
            'bank_lifecycle_status' => 'active',
            'supported_countries' => json_encode(['BW'], JSON_THROW_ON_ERROR),
            'description' => 'Local paid event and funeral contribution checkout simulator. No live bank connection.',
            'metadata_version' => 1,
            'updated_at' => $now,
            'created_at' => $provider?->created_at ?? $now,
        ]);

        $account = $payment->table('payment.payment_provider_accounts')->where('provider_id', $providerId)->first();
        $accountId = $account?->id ?? $this->id('mock-bank-account');
        $payment->table('payment.payment_provider_accounts')->updateOrInsert(['id' => $accountId], [
            'provider_id' => $providerId,
            'merchant_account_reference' => 'LOCAL-EVENT-MOCK-001',
            'callback_secret_reference' => 'PAYMENT_MOCK_BANK_CALLBACK_SECRET',
            'supported_currencies' => json_encode(['BWP'], JSON_THROW_ON_ERROR),
            'supported_payment_methods' => json_encode(['bank_transfer'], JSON_THROW_ON_ERROR),
            'min_amount_minor' => 1,
            'max_amount_minor' => 999999999,
            'enabled_organisations' => json_encode([], JSON_THROW_ON_ERROR),
            // Leave module scope unrestricted because attempt-time account resolution
            // has no trusted module field; purpose scope still limits this account
            // to explicitly enabled payment purposes.
            'enabled_modules' => json_encode([], JSON_THROW_ON_ERROR),
            'enabled_purpose_types' => json_encode(['paid_event_ticket', 'funeral_contribution'], JSON_THROW_ON_ERROR),
            'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'capability_flags' => json_encode(['supports_refund' => true], JSON_THROW_ON_ERROR),
            'fee_rule_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'daily_limit_minor' => null,
            'is_active' => true,
            'config_version' => 1,
            'display_label' => 'Development demo bank',
            'display_description' => 'Local payment simulator; no real money is moved.',
            'display_icon_key' => 'icon.payment.bank',
            'display_badge' => 'LOCAL DEMO',
            'account_lifecycle_status' => 'active',
            'activated_at' => $now,
            'created_at' => $account?->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $payment->table('payment.consumer_payment_options')->updateOrInsert(
            ['account_id' => $accountId, 'payment_method' => 'bank_redirect'],
            [
                'id' => $this->id('mock-bank-consumer-option'),
                'display_label' => 'Development demo bank',
                'display_order' => 1,
                'supported_purpose_types' => json_encode(['paid_event_ticket', 'funeral_contribution'], JSON_THROW_ON_ERROR),
                'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::connection('platform')->table('integration.integrations')->updateOrInsert(
            ['provider_type' => 'payment', 'provider_slug' => 'mock-bank-local'],
            [
                'id' => $this->id('mock-bank-integration'),
                'display_name' => 'Development Mock Bank (local only)',
                'credentials_reference' => 'local.mock_bank',
                'endpoint_override' => null,
                'status' => 'active',
                'is_default' => false,
                'configuration' => json_encode([
                    'payment_provider_account_id' => $accountId,
                    'payment_provider_code' => 'mock_bank',
                    'environment' => 'mock',
                ], JSON_THROW_ON_ERROR),
                'created_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function id(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/paid-event-demo/'.$name)->toString();
    }

    private function ensurePocSponsor($events, string $organisationId, string $eventId, string $creatorId, string $reportActorId): void
    {
        if (! config('events.poc_enabled') || ! config('events.poc_demo_sponsor_enabled')) return;
        $id = $this->id('poc-sponsor');
        $events->table('community_services.event_sponsors')->updateOrInsert(['id' => $id], [
            'organisation_id' => $organisationId, 'name' => 'LOCAL POC Sponsor',
            'description' => 'Local demonstration sponsor profile. This is not a BTC integration or commercial sponsorship.',
            'logo_url' => null, 'report_actor_id' => $reportActorId, 'active' => true,
            'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $events->table('community_services.event_sponsor_links')->insertOrIgnore([
            'event_id' => $eventId, 'sponsor_id' => $id, 'created_at' => now(),
        ]);
        foreach ([['created', null], ['linked', $eventId]] as [$action, $auditedEventId]) {
            $events->table('community_services.event_sponsor_audit')->insertOrIgnore([
                'id' => $this->id('poc-sponsor-audit/'.$action), 'sponsor_id' => $id,
                'event_id' => $auditedEventId, 'actor_id' => $creatorId,
                'action' => $action === 'created' ? 'sponsor_created' : 'sponsor_linked', 'created_at' => now(),
            ]);
        }
    }
}
