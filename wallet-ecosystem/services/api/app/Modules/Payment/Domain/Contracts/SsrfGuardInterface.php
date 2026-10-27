<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Exceptions\SsrfViolationException;

interface SsrfGuardInterface
{
    /**
     * Validate a URL against SSRF protection rules.
     * FR-015: reject non-HTTPS, private IPs, loopback, cloud metadata endpoints.
     *
     * @throws SsrfViolationException
     */
    public function validate(string $url): void;
}
