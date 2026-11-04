<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Domain\ValueObjects\EventVenueCountryCode;
use App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Draft data only: no publication, inventory, payment, ticket or admission capability. */
final class EventDraftService
{
    public function __construct(
        private readonly OrganisationMembershipAuthority $membership,
        private readonly EventDraftAuthorizer $authorizer,
    ) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(string $organisationId, string $actorId, array $input): array
    {
        $this->membership->assertActiveMembership($actorId, $organisationId);
        $this->authorizer->assertCanCreate($actorId, $organisationId);
        $data = $this->validateDraft($input);
        $id = (string) Uuid::uuid7();
        $this->db()->transaction(function () use ($id, $organisationId, $actorId, $data): void {
            $this->db()->table('community_services.event_drafts')->insert($this->fields($data) + [
                'id' => $id, 'organisation_id' => $organisationId, 'created_by' => $actorId,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordVersionContributors($id, 1, [$actorId]);
            $this->insertCategories($id, $data['categories']);
            $this->audit($id, $actorId, 'created', 1);
        });

        return $this->present($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(string $eventId, string $actorId, int $expectedVersion, array $input): array
    {
        $this->db()->transaction(function () use ($eventId, $actorId, $expectedVersion, $input): void {
            $draft = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($draft === null, 404);
            abort_unless(($draft->lifecycle_state ?? 'active') === 'active', 409, 'A cancelled event cannot be edited.');
            $this->membership->assertActiveMembership($actorId, (string) $draft->organisation_id);
            $this->authorizer->assertCanUpdate($actorId, (string) $draft->organisation_id, $eventId);
            if ((int) $draft->version !== $expectedVersion) {
                abort(409, 'Event draft has changed; reload before editing.');
            }
            $contributors = $this->db()->table('community_services.event_draft_version_contributors')
                ->where('event_id', $eventId)->where('version', $expectedVersion)->pluck('actor_id')->map(static fn ($id): string => (string) $id)->all();
            $contributors[] = (string) $draft->created_by;
            $contributors[] = $actorId;
            $data = $this->validateDraft($input);
            $countryChanged = ($data['venue_country_code'] ?? null) !== $draft->venue_country_code;
            $formatChanged = $data['event_format'] !== $draft->event_format;
            if ($draft->currency_locked_at !== null && ($countryChanged || $formatChanged)) {
                abort(409, 'Event location scope cannot change after the first inventory hold or durable order.');
            }
            $version = $expectedVersion + 1;
            $workflowState = $draft->published_version === null ? 'draft' : 'change_pending';
            $this->db()->table('community_services.event_drafts')->where('id', $eventId)->update(
                $this->fields($data, preserveCurrency: true) + [
                    'version' => $version,
                    'workflow_state' => $workflowState,
                    'sales_state' => $draft->published_version === null ? 'not_published' : 'paused',
                    'event_reviewer_id' => null,
                    'reviewed_version' => null,
                    'price_approver_id' => null,
                    'price_approved_version' => null,
                    'rejection_reason' => null,
                    'updated_at' => now(),
                ],
            );
            $this->recordVersionContributors($eventId, $version, $contributors);
            $this->syncCategories($eventId, $data['categories']);
            $this->shortenHoldsForEarlierEventStart($eventId, $this->parseEventInstant(
                $data['starts_at'], (string) $data['timezone'], 'starts_at',
            ));
            $this->audit($eventId, $actorId, 'updated', $version);
        });

        return $this->present($eventId);
    }

    /** @return array<string, mixed> */
    public function get(string $eventId, string $actorId): array
    {
        $draft = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($draft === null, 404);
        $this->membership->assertActiveMembership($actorId, (string) $draft->organisation_id);
        $this->authorizer->assertCanRead($actorId, (string) $draft->organisation_id, $eventId);

        return $this->present($eventId);
    }

    /** @return array<string, mixed> */
    private function present(string $eventId): array
    {
        $draft = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($draft === null, 404);
        $result = (array) $draft;
        $result['sponsors'] = json_decode((string) $draft->sponsors, true, 512, JSON_THROW_ON_ERROR);
        $result['status'] = $draft->workflow_state ?? 'draft';
        $result['sales_state'] = $draft->sales_state ?? 'not_published';
        $result['published_snapshot'] = $draft->published_snapshot === null
            ? null
            : json_decode((string) $draft->published_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $result['categories'] = $this->db()->table('community_services.event_draft_categories')
            ->where('event_id', $eventId)->orderBy('name')->get([
                'id', 'name', 'capacity', 'price_minor', 'allocation_type', 'complimentary_quota',
                'max_per_person',
            ])->toArray();

        return $result;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateDraft(array $input): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'event_format' => ['required', 'string', 'in:physical,online,hybrid'],
            'venue_country_code' => [
                'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D',
                'required_if:event_format,physical', 'prohibited_unless:event_format,physical',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! EventVenueCountryCode::isValid($value)) {
                        $fail('The venue country must be a valid ISO 3166-1 alpha-2 code.');
                    }
                },
            ],
            'organiser_name' => ['required', 'string', 'max:200'],
            'sponsors' => ['present', 'array'], 'sponsors.*' => ['required', 'string', 'max:200'],
            'capacity' => ['required', 'integer', 'min:1'],
            'timezone' => ['required', 'timezone'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'sales_start_at' => ['required', 'date'], 'sales_end_at' => ['required', 'date', 'after:sales_start_at'],
            'max_tickets_per_order' => ['required', 'integer', 'min:1'],
            'hold_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cancellation_policy' => ['required', 'string'], 'transfer_policy' => ['required', 'string'],
            'entry_policy' => ['required', 'string'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.name' => ['required', 'string', 'max:120', 'distinct'],
            'categories.*.capacity' => ['required', 'integer', 'min:1'],
            'categories.*.allocation_type' => ['sometimes', 'string', 'in:paid,free,complimentary'],
            'categories.*.price_minor' => ['required', 'integer', 'min:0'],
            'categories.*.complimentary_quota' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'categories.*.max_per_person' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();
        $this->validateSchedule($data);
        $total = 0;
        foreach ($data['categories'] as &$category) {
            $category['allocation_type'] = $category['allocation_type'] ?? 'paid';
            $category['complimentary_quota'] = $category['complimentary_quota'] ?? null;
            $category['max_per_person'] = $category['max_per_person'] ?? null;
            $price = (int) $category['price_minor'];
            if ($category['allocation_type'] === 'paid' && $price < 1) {
                throw ValidationException::withMessages(['categories' => 'Paid categories require a positive price.']);
            }
            if ($category['allocation_type'] !== 'paid' && $price !== 0) {
                throw ValidationException::withMessages(['categories' => 'Free and complimentary categories must have zero price.']);
            }
            if ($category['allocation_type'] === 'complimentary') {
                $quota = $category['complimentary_quota'];
                if (! is_int($quota) || $quota > (int) $category['capacity']) {
                    throw ValidationException::withMessages(['categories' => 'Complimentary quota must be within category capacity.']);
                }
            } elseif ($category['complimentary_quota'] !== null) {
                throw ValidationException::withMessages(['categories' => 'Only complimentary categories may configure a separate quota.']);
            }
            if ($category['allocation_type'] !== 'paid' && (! is_int($category['max_per_person']) || $category['max_per_person'] < 1)) {
                throw ValidationException::withMessages(['categories' => 'Free allocations require an explicit per-person limit.']);
            }
            if ($category['allocation_type'] === 'paid' && $category['max_per_person'] !== null) {
                throw ValidationException::withMessages(['categories' => 'Per-person free registration limits apply only to free allocations.']);
            }
            $total += (int) $category['capacity'];
        }
        unset($category);
        if ($total > (int) $data['capacity']) {
            throw ValidationException::withMessages(['categories' => 'Category capacity exceeds event capacity.']);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateSchedule(array $data): void
    {
        $instants = [];
        foreach (['starts_at', 'ends_at', 'sales_start_at', 'sales_end_at'] as $field) {
            $instants[$field] = $this->parseEventInstant($data[$field], (string) $data['timezone'], $field);
        }

        $errors = [];
        if ($instants['ends_at'] <= $instants['starts_at']) {
            $errors['ends_at'] = 'The event end must be later than its start.';
        }
        if ($instants['sales_start_at'] >= $instants['sales_end_at']) {
            $errors['sales_end_at'] = 'The sales window must end after it starts.';
        }
        if ($instants['sales_end_at'] > $instants['starts_at']) {
            $errors['sales_end_at'] = 'Ticket sales must end at or before the event starts.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function parseEventInstant(mixed $value, string $timezone, string $field): DateTimeImmutable
    {
        $match = [];
        $pattern = '/^(?<wall>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d{1,6}))?(?<offset>Z|[+-]\d{2}:\d{2})$/D';
        if (! is_string($value) || preg_match($pattern, $value, $match) !== 1) {
            throw ValidationException::withMessages([
                $field => 'Use an ISO 8601 timestamp with an explicit UTC offset and the selected event time zone.',
            ]);
        }

        $fraction = $match['fraction'];
        $hasFraction = $fraction !== '';
        $fraction = str_pad($fraction, 6, '0');

        try {
            $zone = new DateTimeZone($timezone);
            $offset = $match['offset'] === 'Z' ? '+00:00' : $match['offset'];
            $dateText = $match['wall'].($hasFraction ? '.'.$fraction : '').$offset;
            $format = $hasFraction ? '!Y-m-d\\TH:i:s.uP' : '!Y-m-d\\TH:i:sP';
            $instant = DateTimeImmutable::createFromFormat($format, $dateText);
            $parseErrors = DateTimeImmutable::getLastErrors();
        } catch (\Throwable) {
            $instant = false;
            $parseErrors = ['warning_count' => 1, 'error_count' => 1];
            $zone = null;
        }

        if ($instant === false || $zone === null || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
            throw ValidationException::withMessages([$field => 'The event time is not a valid local time.']);
        }

        $local = $instant->setTimezone($zone);
        $expectedLocal = $match['wall'].($hasFraction ? '.'.$fraction : '');
        $actualLocal = $local->format($hasFraction ? 'Y-m-d\\TH:i:s.u' : 'Y-m-d\\TH:i:s');
        if ($actualLocal !== $expectedLocal || $local->getOffset() !== $instant->getOffset()) {
            throw ValidationException::withMessages([$field => 'The timestamp offset does not match the selected event time zone.']);
        }

        if ($this->isAmbiguousLocalTime($match['wall'], $instant, $zone)) {
            throw ValidationException::withMessages([$field => 'This local time is ambiguous because of a daylight-saving change. Choose a different time.']);
        }

        return $instant;
    }

    private function isAmbiguousLocalTime(string $wallTime, DateTimeImmutable $instant, DateTimeZone $zone): bool
    {
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $wallTime, new DateTimeZone('UTC'));
        if ($wall === false) {
            return false;
        }

        $transitions = $zone->getTransitions($instant->getTimestamp() - 172800, $instant->getTimestamp() + 172800);
        if (! is_array($transitions)) {
            return false;
        }

        $wallTimestamp = $wall->getTimestamp();
        for ($index = 1; $index < count($transitions); $index++) {
            $previousOffset = (int) $transitions[$index - 1]['offset'];
            $nextOffset = (int) $transitions[$index]['offset'];
            if ($nextOffset >= $previousOffset) {
                continue;
            }

            $repeatedStart = (int) $transitions[$index]['ts'] + $nextOffset;
            $repeatedEnd = (int) $transitions[$index]['ts'] + $previousOffset;
            if ($wallTimestamp >= $repeatedStart && $wallTimestamp < $repeatedEnd) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function fields(array $data, bool $preserveCurrency = false): array
    {
        unset($data['categories']);
        // Currency is assigned through the Finance/platform control path, not by an organiser draft.
        if ($preserveCurrency) {
            unset($data['currency_code']);
        } else {
            $data['currency_code'] = null;
        }
        $data['sponsors'] = json_encode($data['sponsors'], JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function insertCategories(string $eventId, array $categories): void
    {
        foreach ($categories as $category) {
            $this->db()->table('community_services.event_draft_categories')->insert([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'name' => $category['name'],
                'capacity' => $category['capacity'], 'price_minor' => $category['price_minor'],
                'allocation_type' => $category['allocation_type'] ?? 'paid',
                'complimentary_quota' => $category['complimentary_quota'] ?? null,
                'max_per_person' => $category['max_per_person'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function syncCategories(string $eventId, array $categories): void
    {
        $db = $this->db();
        $existing = $db->table('community_services.event_draft_categories')
            ->where('event_id', $eventId)->lockForUpdate()->get()->keyBy('name');
        $retainedIds = [];
        foreach ($categories as $category) {
            $prior = $existing->get($category['name']);
            if ($prior === null) {
                $this->insertCategories($eventId, [$category]);

                continue;
            }
            $activeHolds = $db->table('community_services.event_inventory_holds')
                ->where('event_id', $eventId)->where('category_id', $prior->id)
                ->where('status', 'held')->where('expires_at', '>', now())->sum('quantity');
            if ((int) $category['capacity'] < (int) $prior->issued_quantity + (int) $activeHolds) {
                throw ValidationException::withMessages([
                    'categories' => 'Category capacity cannot be lower than issued tickets plus active holds.',
                ]);
            }
            $db->table('community_services.event_draft_categories')->where('id', $prior->id)->update([
                'capacity' => $category['capacity'], 'price_minor' => $category['price_minor'],
                'allocation_type' => $category['allocation_type'] ?? 'paid',
                'complimentary_quota' => $category['complimentary_quota'] ?? null,
                'max_per_person' => $category['max_per_person'] ?? null, 'updated_at' => now(),
            ]);
            $retainedIds[] = $prior->id;
        }

        foreach ($existing as $prior) {
            if (in_array($prior->id, $retainedIds, true)) {
                continue;
            }
            $activeHolds = $db->table('community_services.event_inventory_holds')
                ->where('event_id', $eventId)->where('category_id', $prior->id)
                ->where('status', 'held')->where('expires_at', '>', now())->exists();
            abort_if((int) $prior->issued_quantity > 0 || $activeHolds, 422,
                'A category with issued tickets or active holds cannot be removed.');
            $db->table('community_services.event_draft_categories')->where('id', $prior->id)->delete();
        }
    }

    private function shortenHoldsForEarlierEventStart(string $eventId, DateTimeImmutable $newStart): void
    {
        $db = $this->db();
        $now = now()->toImmutable();
        $holds = $db->table('community_services.event_inventory_holds')->where('event_id', $eventId)
            ->where('status', 'held')->where('expires_at', '>', $newStart)->lockForUpdate()->get(['id']);
        foreach ($holds as $hold) {
            $db->table('community_services.event_inventory_holds')->where('id', $hold->id)->update([
                'expires_at' => $newStart,
                'status' => $newStart <= $now ? 'expired' : 'held',
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<int, string> $actorIds */
    private function recordVersionContributors(string $eventId, int $version, array $actorIds): void
    {
        foreach (array_unique($actorIds) as $actorId) {
            $this->db()->table('community_services.event_draft_version_contributors')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId,
                'version' => $version, 'actor_id' => $actorId, 'created_at' => now(),
            ]);
        }
    }

    private function audit(string $eventId, string $actorId, string $action, int $version): void
    {
        $auditId = (string) Uuid::uuid7();
        $this->db()->table('community_services.event_draft_audit')->insert([
            'id' => $auditId, 'event_id' => $eventId, 'actor_id' => $actorId,
            'action' => $action, 'version' => $version, 'created_at' => now(),
        ]);
        $this->db()->afterCommit(static function () use ($auditId, $eventId, $actorId, $action, $version): void {
            app(AuditServiceInterface::class)->record(new AuditEventData(
                action: 'events.draft_'.$action,
                actorType: 'user',
                resourceType: 'event_draft',
                correlationId: $auditId,
                outcome: 'success',
                actorId: $actorId,
                resourceId: $eventId,
                afterState: ['version' => $version],
            ));
        });
    }
}
