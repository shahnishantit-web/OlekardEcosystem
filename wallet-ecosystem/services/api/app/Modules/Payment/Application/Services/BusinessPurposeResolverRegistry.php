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
