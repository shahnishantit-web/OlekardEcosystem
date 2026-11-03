<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\PaymentContextIdentifiers;
use App\Modules\Payment\Infrastructure\Stubs\DevPurposeStubResolver;
use LogicException;
use Tests\TestCase;

final class BusinessPurposeResolverRegistryCollisionTest extends TestCase
{
    public function test_repeated_registration_of_the_same_resolver_is_idempotent(): void
    {
        $registry = new BusinessPurposeResolverRegistry;
        $registry->register('dev_test_purpose', DevPurposeStubResolver::class);
        $registry->register('dev_test_purpose', new DevPurposeStubResolver);

        self::assertInstanceOf(DevPurposeStubResolver::class, $registry->resolverFor('dev_test_purpose'));
    }

    public function test_conflicting_resolver_registration_is_rejected_without_replacing_owner(): void
    {
        $registry = new BusinessPurposeResolverRegistry;
        $resolver = new DevPurposeStubResolver;
        $registry->register('dev_test_purpose', $resolver);

        try {
            $registry->register('dev_test_purpose', \stdClass::class);
            self::fail('A conflicting resolver must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already registered', $exception->getMessage());
        }

        self::assertSame($resolver, $registry->resolverFor('dev_test_purpose'));
    }

    public function test_paid_event_purpose_is_named_but_not_payable_until_an_order_resolver_exists(): void
    {
        self::assertSame('events', PaymentContextIdentifiers::MODULE_EVENTS);
        self::assertSame('paid_event_ticket', PaymentContextIdentifiers::PURPOSE_PAID_EVENT_TICKET);

        $registry = new BusinessPurposeResolverRegistry;
        self::assertFalse($registry->isRegistered(PaymentContextIdentifiers::PURPOSE_PAID_EVENT_TICKET));
    }
}
