<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\SsrfGuardInterface;
use App\Modules\Payment\Domain\Exceptions\SsrfViolationException;

/**
 * FR-015: SSRF protection — validates configurable base URLs at save time.
 *
 * Blocked:
 *  - Non-HTTPS schemes
 *  - RFC1918 private ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
 *  - Loopback: 127.0.0.0/8, ::1
 *  - Link-local: 169.254.0.0/16
 *  - Cloud metadata IPs: 169.254.169.254, fd00:ec2::254
 *  - Non-standard ports (only 443 or absent)
 */
final class SsrfGuard implements SsrfGuardInterface
{
    private const METADATA_IPS = ['169.254.169.254', 'fd00:ec2::254'];

    private const PRIVATE_RANGES = [
        ['10.0.0.0',   '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
    ];

    private const LOOPBACK_RANGES = [
        ['127.0.0.0', '127.255.255.255'],
    ];

    private const LINK_LOCAL_RANGE = ['169.254.0.0', '169.254.255.255'];

    public function validate(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            throw new SsrfViolationException('URL could not be parsed or has no host', $url);
        }

        $scheme = strtolower($parsed['scheme'] ?? '');

        if ($scheme !== 'https') {
            throw SsrfViolationException::nonHttps($scheme ?: 'missing');
        }

        $port = $parsed['port'] ?? null;

        if ($port !== null && $port !== 443) {
            throw SsrfViolationException::disallowedPort((string) $port);
        }

        $host   = $parsed['host'];
        $hostIp = $this->resolveHost($host);

        if ($hostIp === null) {
            return;
        }

        $this->guardIp($hostIp, $host);
    }

    private function resolveHost(string $host): ?string
    {
        // Strip IPv6 brackets
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $resolved = gethostbyname($host);

        if ($resolved === $host) {
            return null;
        }

        return $resolved;
    }

    private function guardIp(string $ip, string $host): void
    {
        foreach (self::METADATA_IPS as $metaIp) {
            if ($ip === $metaIp) {
                throw SsrfViolationException::metadataEndpoint($host);
            }
        }

        if ($ip === '::1') {
            throw SsrfViolationException::loopbackAddress($host);
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return;
        }

        $ipLong = ip2long($ip);

        foreach (self::LOOPBACK_RANGES as [$start, $end]) {
            if ($ipLong >= ip2long($start) && $ipLong <= ip2long($end)) {
                throw SsrfViolationException::loopbackAddress($host);
            }
        }

        $llStart = ip2long(self::LINK_LOCAL_RANGE[0]);
        $llEnd   = ip2long(self::LINK_LOCAL_RANGE[1]);

        if ($ipLong >= $llStart && $ipLong <= $llEnd) {
            if ($ip === '169.254.169.254') {
                throw SsrfViolationException::metadataEndpoint($host);
            }

            throw SsrfViolationException::loopbackAddress($host);
        }

        foreach (self::PRIVATE_RANGES as [$start, $end]) {
            if ($ipLong >= ip2long($start) && $ipLong <= ip2long($end)) {
                throw SsrfViolationException::privateIpRange($host);
            }
        }
    }
}
