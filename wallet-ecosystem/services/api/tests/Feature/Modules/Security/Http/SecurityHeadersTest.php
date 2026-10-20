<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function response_includes_strict_transport_security_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=', $response->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    /** @test */
    public function response_includes_x_frame_options_deny(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /** @test */
    public function response_includes_x_content_type_options_nosniff(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @test */
    public function response_includes_referrer_policy_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /** @test */
    public function response_includes_content_security_policy_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('Content-Security-Policy');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($csp);
    }

    /** @test */
    public function response_includes_permissions_policy_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('Permissions-Policy');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    /** @test */
    public function csp_header_value_reflects_config(): void
    {
        config(['security.csp_policy' => "default-src 'self'; frame-ancestors 'none'"]);

        $response = $this->getJson('/api/v1/health');

        $this->assertSame(
            "default-src 'self'; frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    /** @test */
    public function permissions_policy_header_value_reflects_config(): void
    {
        config(['security.permissions_policy' => 'camera=(), microphone=(), payment=()']);

        $response = $this->getJson('/api/v1/health');

        $this->assertSame(
            'camera=(), microphone=(), payment=()',
            $response->headers->get('Permissions-Policy')
        );
    }
}
