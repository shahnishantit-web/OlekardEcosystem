<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Exceptions\UnknownPurposeTypeException;

final class BusinessPurposeResolverRegistry
{
    /** @var array<string, class-string<BusinessPurposeResolverInterface>|BusinessPurposeResolverInterface> */
    private array $resolvers = [];

    public function register(string $purposeType, BusinessPurposeResolverInterface|string $resolver): void
    {
        if (array_key_exists($purposeType, $this->resolvers)) {
            $registered = $this->resolvers[$purposeType];
            $registeredClass = $registered instanceof BusinessPurposeResolverInterface ? $registered::class : $registered;
            $incomingClass = $resolver instanceof BusinessPurposeResolverInterface ? $resolver::class : $resolver;

            // Re-registering the same resolver is an idempotent provider boot; a different
            // resolver claiming the same purpose is an ownership collision and must fail.
            if ($registeredClass !== $incomingClass) {
                throw new \LogicException(sprintf(
                    'Payment purpose [%s] is already registered to [%s]; it cannot also be registered to [%s].',
                    $purposeType,
                    $registeredClass,
                    $incomingClass,
                ));
            }

            return;
        }

        if (is_string($resolver) && ! is_subclass_of($resolver, BusinessPurposeResolverInterface::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Payment purpose resolver [%s] must implement [%s].',
                $resolver,
                BusinessPurposeResolverInterface::class,
            ));
        }

        $this->resolvers[$purposeType] = $resolver;
    }

    public function resolverFor(string $purposeType): BusinessPurposeResolverInterface
    {
        if (! array_key_exists($purposeType, $this->resolvers)) {
            throw new UnknownPurposeTypeException($purposeType);
        }

        $resolver = $this->resolvers[$purposeType];

        if ($resolver instanceof BusinessPurposeResolverInterface) {
            return $resolver;
        }

        /** @var BusinessPurposeResolverInterface */
        return app($resolver);
    }

    public function isRegistered(string $purposeType): bool
    {
        return array_key_exists($purposeType, $this->resolvers);
    }
}
