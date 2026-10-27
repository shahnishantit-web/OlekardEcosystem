<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Events;

use App\Modules\Events\Application\EventDraftService;
use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class EventDraftFoundationTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'community_services'];

    private string $actor;

    private string $organisation;

    private string $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = (string) Uuid::uuid7();
        $this->organisation = (string) Uuid::uuid7();
        $this->membership = (string) Uuid::uuid7();
        $db = DB::connection('identity');
        $db->table('identity.users')->insert(['id' => $this->actor, 'status' => 'active',
            'actor_type' => 'ADMIN', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('identity.organisations')->insert(['id' => $this->organisation,
            'registry_reference' => 'p25-test-'.Uuid::uuid7(), 'display_name' => 'Test organisation',
            'status' => 'active', 'evidence_reference' => 'test-only', 'requested_by' => $this->actor,
            'reviewed_by' => (string) Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('identity.organisation_memberships')->insert(['id' => $this->membership,
            'organisation_id' => $this->organisation, 'user_id' => $this->actor, 'status' => 'active',
            'evidence_reference' => 'test-only', 'requested_by' => (string) Uuid::uuid7(),
            'reviewed_by' => (string) Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_default_authority_denies_drafts_even_for_a_member(): void
    {
        $this->assertHttp(403, fn () => app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input()));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_drafts')
            ->where('organisation_id', $this->organisation)->count());
    }

    public function test_draft_is_scoped_and_revocation_stops_access(): void
    {
        $this->allowTestAuthorizer();
        $service = app(EventDraftService::class);
        $draft = $service->create($this->organisation, $this->actor, $this->input());
        $this->assertSame('draft', $draft['status']);
        $this->assertSame(['Example sponsor'], $draft['sponsors']);
        $this->assertCount(2, $draft['categories']);
        $this->assertSame('Supplied entry terms', $draft['entry_policy']);
        $this->assertSame(6, (int) $draft['max_tickets_per_order']);
        $this->assertNull($draft['currency_code']);
        $this->assertHttp(403, fn () => $service->create((string) Uuid::uuid7(), $this->actor, $this->input()));
        DB::connection('identity')->table('identity.organisation_memberships')->where('id', $this->membership)->update(['status' => 'revoked']);
        $this->assertHttp(403, fn () => $service->get($draft['id'], $this->actor));
        $this->assertHttp(403, fn () => $service->update($draft['id'], $this->actor, 1, $this->input()));
        $this->assertFalse(method_exists($service, 'publish'));
    }

    public function test_capacity_and_version_checks_preserve_existing_draft(): void
    {
        $this->allowTestAuthorizer();
        $service = app(EventDraftService::class);
        $draft = $service->create($this->organisation, $this->actor, $this->input());
        $bad = $this->input();
        $bad['categories'][0]['capacity'] = 99;
        try {
            $service->update($draft['id'], $this->actor, 1, $bad);
            $this->fail('Category capacity must not exceed the event capacity.');
        } catch (ValidationException) {
            $this->assertSame(1, (int) $service->get($draft['id'], $this->actor)['version']);
        }
        $good = $this->input();
        $good['title'] = 'Updated draft';
        $updated = $service->update($draft['id'], $this->actor, 1, $good);
        $this->assertSame(2, (int) $updated['version']);
        $this->assertSame('Updated draft', $updated['title']);
        $this->assertHttp(409, fn () => $service->update($draft['id'], $this->actor, 1, $good));
        $events = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->orderBy('version')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['created', 'updated'], $events->pluck('action')->all());
        $this->assertStringNotContainsString('Supplied entry terms', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function test_caller_cannot_override_draft_ownership_or_version(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['currency_code'] = 'XXX';
        $input['organisation_id'] = (string) Uuid::uuid7();
        $input['created_by'] = (string) Uuid::uuid7();
        $input['version'] = 700;
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);

        $this->assertSame($this->organisation, $draft['organisation_id']);
        $this->assertSame($this->actor, $draft['created_by']);
        $this->assertSame(1, (int) $draft['version']);
        $this->assertNull($draft['currency_code'], 'Currency is assigned by Finance/platform controls, not by the organiser draft.');
        $this->assertSame('draft', $draft['status']);
    }

    public function test_sales_window_must_end_at_or_before_event_start(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['sales_end_at'] = '2027-03-01T10:00:00+02:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('Sales cannot remain open after the event starts.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sales_end_at', $exception->errors());
        }
    }

    public function test_schedule_rejects_invalid_timezone_offsets_and_daylight_saving_gaps(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['starts_at'] = '2027-03-01T09:00:00+00:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('The timestamp offset must match the selected event time zone.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $input = $this->input();
        $input['timezone'] = 'America/New_York';
        $input['starts_at'] = '2027-03-14T02:30:00-05:00';
        $input['ends_at'] = '2027-03-14T04:30:00-04:00';
        $input['sales_start_at'] = '2027-01-01T09:00:00-05:00';
        $input['sales_end_at'] = '2027-03-13T09:00:00-05:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('A nonexistent local time must be rejected rather than shifted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function test_schedule_rejects_ambiguous_daylight_saving_times(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['timezone'] = 'America/New_York';
        $input['starts_at'] = '2027-11-07T01:30:00-04:00';
        $input['ends_at'] = '2027-11-07T03:30:00-05:00';
        $input['sales_start_at'] = '2027-01-01T09:00:00-05:00';
        $input['sales_end_at'] = '2027-11-06T09:00:00-05:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('An ambiguous local time must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function test_order_limit_is_required_and_positive(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['max_tickets_per_order'] = 0;

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('The proposed maximum quantity must be positive.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('max_tickets_per_order', $exception->errors());
        }
    }

    private function allowTestAuthorizer(): void
    {
        // Explicit test-only substitute; the production binding denies all.
        $this->app->instance(EventDraftAuthorizer::class, new class implements EventDraftAuthorizer
        {
            public function assertCanManageDraft(string $actorId, string $organisationId): void {}
        });
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['title' => 'Test event', 'organiser_name' => 'Test organiser', 'sponsors' => ['Example sponsor'],
            'capacity' => 10, 'currency_code' => 'BWP', 'timezone' => 'Africa/Gaborone',
            'max_tickets_per_order' => 6,
            'starts_at' => '2027-03-01T09:00:00+02:00', 'ends_at' => '2027-03-01T12:00:00+02:00',
            'sales_start_at' => '2027-01-01T09:00:00+02:00', 'sales_end_at' => '2027-02-28T09:00:00+02:00',
            'cancellation_policy' => 'Supplied cancellation terms', 'transfer_policy' => 'Supplied transfer terms',
            'entry_policy' => 'Supplied entry terms', 'categories' => [
                ['name' => 'A', 'capacity' => 6, 'price_minor' => 1000],
                ['name' => 'B', 'capacity' => 4, 'price_minor' => 2000],
            ]];
    }

    private function assertHttp(int $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$expected);
        } catch (HttpException $exception) {
            $this->assertSame($expected, $exception->getStatusCode());
        }
    }
}
