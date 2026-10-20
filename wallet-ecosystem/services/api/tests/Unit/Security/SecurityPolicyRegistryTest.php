<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry as Registry;
use PHPUnit\Framework\TestCase;

final class SecurityPolicyRegistryTest extends TestCase
{
    public function test_network_boundaries_support_both_ip_families(): void
    {
        $this->assertTrue(Registry::allowsIp('192.0.2.255', ['192.0.2.0/24']));
        $this->assertFalse(Registry::allowsIp('192.0.3.0', ['192.0.2.0/24']));
        $this->assertTrue(Registry::allowsIp('2001:db8::ffff', ['2001:db8::/32']));
        $this->assertFalse(Registry::allowsIp('2001:db9::1', ['2001:db8::/32']));
        $this->assertTrue(Registry::allowsIp('203.0.113.1', []));
    }

    public function test_only_exact_configuration_envelopes_are_unwrapped(): void
    {
        $this->assertSame(false, Registry::unwrap(['value' => false]));
        $this->assertNull(Registry::unwrap(['value' => null]));
        $this->assertSame(['value' => 'required', 'admin' => 'optional'], Registry::unwrap(['value' => 'required', 'admin' => 'optional']));
    }
}
