<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use App\Modules\Payment\Application\Services\SsrfGuard;
use App\Modules\Payment\Domain\Exceptions\SsrfViolationException;
use PHPUnit\Framework\TestCase;

/**
 * T042: SSRF guard unit tests — all 9 rejection vectors from SC-002 / T096.
 */
final class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    public function test_non_https_url_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('http://bank.example.com/api');
    }

    public function test_private_ip_10x_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://10.0.0.1/api');
    }

    public function test_private_ip_172_16x_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://172.16.0.1/api');
    }

    public function test_private_ip_192_168x_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://192.168.1.100/api');
    }

    public function test_loopback_127x_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://127.0.0.1/api');
    }

    public function test_loopback_ipv6_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://[::1]/api');
    }

    public function test_link_local_169_254x_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://169.254.1.1/api');
    }

    public function test_metadata_ip_169_254_169_254_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://169.254.169.254/latest/meta-data');
    }

    public function test_ipv6_metadata_fd00_ec2_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://[fd00:ec2::254]/latest/meta-data');
    }

    public function test_non_standard_port_is_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://bank.example.com:8080/api');
    }

    public function test_valid_https_url_passes(): void
    {
        $this->guard->validate('https://sandbox.fnb.co.bw/api/v1/payments');

        $this->assertTrue(true);
    }

    public function test_valid_https_url_without_port_passes(): void
    {
        $this->guard->validate('https://api.bank.example.com/payments/initiate');

        $this->assertTrue(true);
    }

    public function test_valid_https_url_with_port_443_passes(): void
    {
        $this->guard->validate('https://api.bank.example.com:443/payments');

        $this->assertTrue(true);
    }
}
