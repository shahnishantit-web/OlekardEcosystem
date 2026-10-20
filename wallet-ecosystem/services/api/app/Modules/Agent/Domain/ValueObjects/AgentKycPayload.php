<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\ValueObjects;

final readonly class AgentKycPayload
{
    public function __construct(
        public string  $applicationId,
        public string  $firstName,
        public string  $lastName,
        public string  $nationalIdNumber,
        public string  $nationalIdType,
        public string  $dateOfBirth,
        public string  $phone,
        public array   $address,
        public ?string $tradingName = null,
        public ?string $businessRegistrationNumber = null,
    ) {}
}
