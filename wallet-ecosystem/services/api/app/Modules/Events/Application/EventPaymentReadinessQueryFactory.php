<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\ValueObjects\EventVenueCountryCode;
use App\Modules\Payment\Domain\PaymentContextIdentifiers;
use App\Modules\Payment\Domain\ValueObjects\EventStaticReadinessQuery;
use App\Modules\Payment\Domain\ValueObjects\StaticEligibilityContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Builds actor-free payment contexts exclusively from an approved Events record. */
final class EventPaymentReadinessQueryFactory
{
    public function forPublication(string $eventId): EventStaticReadinessQuery
    {
        $db = DB::connection('community_services');
        if ($db->transactionLevel() < 1) {
            throw new LogicException('Event readiness must be derived inside the locked Events workflow transaction.');
        }

        $event = $db->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        abort_unless(
            $event->workflow_state === 'approved'
                && (int) $event->reviewed_version === (int) $event->version
                && (int) $event->price_approved_version === (int) $event->version
                && $event->event_reviewer_id !== null
                && $event->price_approver_id !== null
                && $event->event_reviewer_id !== $event->price_approver_id,
            409,
            'Payment readiness requires distinct approvals for the current event version.',
        );

        return $this->fromApprovedRecord(
            eventId: (string) $event->id,
            version: (int) $event->version,
            organisationId: (string) $event->organisation_id,
            format: (string) $event->event_format,
            countryCode: $event->venue_country_code === null ? null : (string) $event->venue_country_code,
            currencyCode: $event->currency_code === null ? null : (string) $event->currency_code,
            categories: $db->table('community_services.event_draft_categories')->where('event_id', $eventId)
                ->orderBy('name')->get(['id', 'price_minor', 'allocation_type'])->map(static fn (object $category): array => (array) $category)->all(),
        );
    }

    public function forSalesResumption(string $eventId): EventStaticReadinessQuery
    {
        $db = DB::connection('community_services');
        if ($db->transactionLevel() < 1) {
            throw new LogicException('Event readiness must be derived inside the locked Events workflow transaction.');
        }

        $event = $db->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        abort_unless(
            in_array($event->workflow_state, ['published', 'rejected'], true)
                && $event->published_version !== null
                && $event->published_snapshot !== null,
            409,
            'No eligible approved event snapshot exists.',
        );
        $snapshot = json_decode((string) $event->published_snapshot, true, 512, JSON_THROW_ON_ERROR);
        abort_unless((int) ($snapshot['version'] ?? 0) === (int) $event->published_version, 409, 'Published event snapshot is stale.');

        return $this->fromApprovedRecord(
            eventId: (string) $event->id,
            version: (int) $event->published_version,
            organisationId: (string) ($snapshot['organisation_id'] ?? ''),
            format: (string) ($snapshot['event_format'] ?? ''),
            countryCode: isset($snapshot['venue_country_code']) ? (string) $snapshot['venue_country_code'] : null,
            currencyCode: isset($snapshot['currency_code']) ? (string) $snapshot['currency_code'] : null,
            categories: $snapshot['categories'] ?? [],
        );
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function fromApprovedRecord(
        string $eventId,
        int $version,
        string $organisationId,
        string $format,
        ?string $countryCode,
        ?string $currencyCode,
        array $categories,
    ): EventStaticReadinessQuery {
        if ($format !== 'physical' || $countryCode === null || ! EventVenueCountryCode::isValid($countryCode)) {
            throw new \DomainException('Paid event readiness supports only an approved physical event with one venue country.');
        }
        if ($currencyCode === null || preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1 || $organisationId === '') {
            throw new \DomainException('Paid event readiness requires an approved organisation and assigned currency.');
        }

        $contexts = [];
        foreach ($categories as $category) {
            $allocationType = (string) ($category['allocation_type'] ?? 'paid');
            if (in_array($allocationType, ['free', 'complimentary'], true)) {
                if ((int) ($category['price_minor'] ?? -1) !== 0) {
                    throw new \DomainException('Free and complimentary allocations must have zero price.');
                }

                continue;
            }
            if ($allocationType !== 'paid') {
                throw new \DomainException('Event category allocation type is unsupported.');
            }
            $categoryId = (string) ($category['id'] ?? '');
            $priceMinor = (int) ($category['price_minor'] ?? 0);
            if ($categoryId === '' || $priceMinor < 1) {
                throw new \DomainException('Every sellable paid category requires an authoritative positive price.');
            }
            $contexts[$categoryId] = new StaticEligibilityContext(
                purposeType: PaymentContextIdentifiers::PURPOSE_PAID_EVENT_TICKET,
                currencyCode: $currencyCode,
                amountMinor: $priceMinor,
                organisationId: $organisationId,
                countryCode: $countryCode,
                moduleKey: PaymentContextIdentifiers::MODULE_EVENTS,
            );
        }
        if ($contexts === []) {
            throw new \DomainException('Free-only publication readiness requires an approved free-event approval contract.');
        }

        return new EventStaticReadinessQuery($eventId, $version, $contexts);
    }
}
