<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class ServiceActor
{
    public function __construct(
        public string  $serviceId,
        public string  $actorType,    // SERVICE
        public array   $scopes,
        public ?string $onBehalfOf,   // user UUID or null
    ) {}
}
