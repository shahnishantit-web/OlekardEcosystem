<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

readonly class IssueServiceTokenCommand
{
    public function __construct(
        public string  $serviceId,
        public array   $scopes,
        public string  $audience,
        public ?string $onBehalfOf,
        public string  $correlationId,
    ) {}
}
