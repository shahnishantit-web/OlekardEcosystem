<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class SsrfViolationException extends DomainException
{
    public function __construct(string $reason, string $offendingPattern = '')
    {
        $message = 'SSRF protection: '.$reason;

        if ($offendingPattern !== '') {
            $message .= ' (pattern: '.$offendingPattern.')';
        }

        parent::__construct($message);
    }

    public static function nonHttps(string $scheme): self
    {
        return new self('URL scheme must be https', $scheme);
    }

    public static function privateIpRange(string $host): self
    {
        return new self('host resolves to a private IP range (RFC1918)', $host);
    }

    public static function loopbackAddress(string $host): self
    {
        return new self('host resolves to a loopback address', $host);
    }

    public static function metadataEndpoint(string $host): self
    {
        return new self('host resolves to a cloud metadata endpoint', $host);
    }

    public static function disallowedPort(string $port): self
    {
        return new self('port not allowed (only 443 or absent)', $port);
    }
}
