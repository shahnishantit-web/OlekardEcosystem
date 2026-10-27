<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Security;

use App\Modules\Payment\Application\Services\SsrfGuard;
use App\Modules\Payment\Domain\Exceptions\SsrfViolationException;
use PHPUnit\Framework\TestCase;

/**
 * T096: SSRF security test — all 9 attack vectors must throw SsrfViolationException.
 * FR-015: Block internal IPs, private IP ranges, cloud metadata endpoints.
 */
final class SsrfGuardSecurityTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    public function test_vector_1_http_scheme_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('http://api.example.com/v1/payments');
    }

    public function test_vector_2_private_ip_10x_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://10.0.0.1/api');
    }

    public function test_vector_3_private_ip_172_16x_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://172.16.0.1/api');
    }

    public function test_vector_4_private_ip_192_168x_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://192.168.1.1/api');
    }

    public function test_vector_5_loopback_127x_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://127.0.0.1/api');
    }

    public function test_vector_6_loopback_localhost_rejected(): void
    {
        // localhost resolves to 127.0.0.1 on most systems
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://localhost/api');
    }

    public function test_vector_7_link_local_169_254x_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://169.254.0.1/api');
    }

    public function test_vector_8_cloud_metadata_ip_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://169.254.169.254/latest/meta-data/');
    }

    public function test_vector_9_ipv6_metadata_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard->validate('https://[fd00:ec2::254]/latest/meta-data/');
    }

    public function test_valid_https_url_passes(): void
    {
        // External HTTPS URL should NOT throw when the host resolves to a public IP.
        // In unit test environment the host may not resolve — skip if so.
        $host = gethostbyname('example.com');
        if ($host === 'example.com') {
            $this->markTestSkipped('example.com did not resolve in this environment — DNS not available');
        }

        // No exception = pass
        $this->guard->validate('https://example.com/api/v1/payment');
        $this->assertTrue(true);
    }
}
