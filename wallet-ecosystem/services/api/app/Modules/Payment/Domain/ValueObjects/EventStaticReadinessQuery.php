<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

/** Caller must supply owner-registered purpose/module and authoritative country context. */
final readonly class EventStaticReadinessQuery
{
    /** @param array<string, StaticEligibilityContext> $categoryContexts keyed by opaque category ID */
    public function __construct(
        public string $eventId,
        public int $eventVersion,
        public array $categoryContexts,
    ) {
        if ($eventId === '' || $eventVersion < 1 || $categoryContexts === []) {
            throw new \InvalidArgumentException('Event readiness requires an event version and at least one sellable category.');
        }
        $shared = null;
        foreach ($categoryContexts as $categoryId => $context) {
            if ($categoryId === '' || ! $context instanceof StaticEligibilityContext) {
                throw new \InvalidArgumentException('Each category requires a typed actor-free eligibility context.');
            }
            $candidate = [
                $context->purposeType, $context->currencyCode, $context->organisationId,
                $context->countryCode, $context->moduleKey, $context->requiredCapability,
            ];
            if ($shared !== null && $shared !== $candidate) {
                throw new \InvalidArgumentException('All categories must share event purpose, currency, organisation, country, module, and capability context.');
            }
            $shared = $candidate;
        }
    }
}
