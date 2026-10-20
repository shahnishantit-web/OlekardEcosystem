<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates that key API endpoints reject SQL injection and XSS payloads.
 * SC-002: Automated injection scanner returns zero HIGH/CRITICAL findings.
 */
final class InjectionPreventionTest extends TestCase
{
    use RefreshDatabase;

    private const SQL_INJECTION_PAYLOAD = "' OR '1'='1 --";
    private const XSS_PAYLOAD           = '<script>alert(document.cookie)</script>';

    // -------------------------------------------------------------------------
    // SQL Injection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function login_phone_field_rejects_sql_injection(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone'    => self::SQL_INJECTION_PAYLOAD,
            'password' => 'anypass',
        ]);

        $this->assertNotEquals(500, $response->getStatusCode(), 'Endpoint returned 500 on SQL injection payload');
        $response->assertStatus(422);
    }

    /** @test */
    public function transfer_note_field_rejects_sql_injection(): void
    {
        $response = $this->postJson('/api/v1/wallets/00000000-0000-0000-0000-000000000000/transfers', [
            'recipient_wallet_id' => '00000000-0000-0000-0000-000000000001',
            'amount'              => 100,
            'currency'            => 'USD',
            'note'                => self::SQL_INJECTION_PAYLOAD,
        ]);

        $this->assertNotEquals(500, $response->getStatusCode(), 'Endpoint returned 500 on SQL injection payload in note field');
        // 401/403 (auth required) or 422 (validation) — never 500
        $this->assertContains($response->getStatusCode(), [401, 403, 422]);
    }

    /** @test */
    public function transaction_search_parameter_rejects_sql_injection(): void
    {
        $response = $this->getJson('/api/v1/transactions?search='.urlencode(self::SQL_INJECTION_PAYLOAD));

        $this->assertNotEquals(500, $response->getStatusCode(), 'Endpoint returned 500 on SQL injection in query param');
        $this->assertContains($response->getStatusCode(), [200, 401, 403, 422]);
    }

    /** @test */
    public function login_endpoint_never_returns_500_on_malformed_input(): void
    {
        $maliciousPayloads = [
            ['phone' => "'; DROP TABLE users; --", 'password' => 'x'],
            ['phone' => '1 UNION SELECT null,null,null--', 'password' => 'x'],
            ['phone' => '\\x27 OR 1=1', 'password' => 'x'],
        ];

        foreach ($maliciousPayloads as $payload) {
            $response = $this->postJson('/api/v1/auth/login', $payload);
            $this->assertNotEquals(500, $response->getStatusCode(), "Endpoint returned 500 for payload: ".json_encode($payload));
        }
    }

    // -------------------------------------------------------------------------
    // XSS Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function response_includes_csp_header_to_prevent_xss_execution(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone'    => self::XSS_PAYLOAD,
            'password' => 'anypass',
        ]);

        $this->assertNotEquals(500, $response->getStatusCode(), 'Endpoint returned 500 on XSS payload');
        // CSP header must be present — prevents injected script from executing in-browser
        $response->assertHeader('Content-Security-Policy');
    }

    /** @test */
    public function xss_payload_in_text_field_is_not_executed_and_csp_is_set(): void
    {
        // Submit XSS payload as a transaction note (requires auth; test the transport + header)
        $response = $this->postJson('/api/v1/wallets/00000000-0000-0000-0000-000000000000/transfers', [
            'recipient_wallet_id' => '00000000-0000-0000-0000-000000000001',
            'amount'              => 100,
            'currency'            => 'USD',
            'note'                => self::XSS_PAYLOAD,
        ]);

        // CSP prevents script execution in browser regardless of stored value
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'Content-Security-Policy header missing');
        // CSP must not allow unsafe-inline scripts
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
    }
}
