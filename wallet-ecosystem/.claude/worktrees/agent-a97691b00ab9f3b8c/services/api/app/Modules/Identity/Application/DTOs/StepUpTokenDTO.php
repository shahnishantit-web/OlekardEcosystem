<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class StepUpTokenDTO
{
    public function __construct(
        public string $stepUpAccessToken,
        public string $scope,
        public int    $expiresIn,
    ) {}
}
