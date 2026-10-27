<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
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
        $this->authorize($actorId, $organisationId);
        $data = $this->validateDraft($input);
        $id = (string) Uuid::uuid7();
        $this->db()->transaction(function () use ($id, $organisationId, $actorId, $data): void {
            $this->db()->table('community_services.event_drafts')->insert($this->fields($data) + [
                'id' => $id, 'organisation_id' => $organisationId, 'created_by' => $actorId,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertCategories($id, $data['categories']);
            $this->audit($id, $actorId, 'created', 1);
        });

        return $this->get($id, $actorId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(string $eventId, string $actorId, int $expectedVersion, array $input): array
    {
        $this->db()->transaction(function () use ($eventId, $actorId, $expectedVersion, $input): void {
            $draft = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($draft === null, 404);
            $this->authorize($actorId, (string) $draft->organisation_id);
            if ((int) $draft->version !== $expectedVersion) {
                abort(409, 'Event draft has changed; reload before editing.');
            }
            $data = $this->validateDraft($input);
            $version = $expectedVersion + 1;
            $this->db()->table('community_services.event_drafts')->where('id', $eventId)->update(
                $this->fields($data) + ['version' => $version, 'updated_at' => now()],
            );
            $this->db()->table('community_services.event_draft_categories')->where('event_id', $eventId)->delete();
            $this->insertCategories($eventId, $data['categories']);
            $this->audit($eventId, $actorId, 'updated', $version);
        });

        return $this->get($eventId, $actorId);
    }

    /** @return array<string, mixed> */
    public function get(string $eventId, string $actorId): array
    {
        $draft = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($draft === null, 404);
        $this->authorize($actorId, (string) $draft->organisation_id);
        $result = (array) $draft;
        $result['sponsors'] = json_decode((string) $draft->sponsors, true, 512, JSON_THROW_ON_ERROR);
        $result['status'] = 'draft';
        $result['categories'] = $this->db()->table('community_services.event_draft_categories')
            ->where('event_id', $eventId)->orderBy('name')->get(['id', 'name', 'capacity', 'price_minor'])->toArray();

        return $result;
    }

    private function authorize(string $actorId, string $organisationId): void
    {
        $this->membership->assertActiveMembership($actorId, $organisationId);
        $this->authorizer->assertCanManageDraft($actorId, $organisationId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateDraft(array $input): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'organiser_name' => ['required', 'string', 'max:200'],
            'sponsors' => ['present', 'array'], 'sponsors.*' => ['required', 'string', 'max:200'],
            'capacity' => ['required', 'integer', 'min:1'],
            'timezone' => ['required', 'timezone'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'sales_start_at' => ['required', 'date'], 'sales_end_at' => ['required', 'date', 'after:sales_start_at'],
            'max_tickets_per_order' => ['required', 'integer', 'min:1'],
            'cancellation_policy' => ['required', 'string'], 'transfer_policy' => ['required', 'string'],
            'entry_policy' => ['required', 'string'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.name' => ['required', 'string', 'max:120', 'distinct'],
            'categories.*.capacity' => ['required', 'integer', 'min:1'],
            'categories.*.price_minor' => ['required', 'integer', 'min:1'],
        ])->validate();
        $this->validateSchedule($data);
        $total = 0;
        foreach ($data['categories'] as $category) {
            $total += (int) $category['capacity'];
        }
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
    private function fields(array $data): array
    {
        unset($data['categories']);
        // Currency is assigned through the Finance/platform control path, not by an organiser draft.
        $data['currency_code'] = null;
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
                'created_at' => now(), 'updated_at' => now(),
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
