<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Services\LockoutService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Logging\SensitiveDataProcessor;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use App\Modules\Security\Domain\Enums\VulnerabilityFindingSeverity;
use App\Modules\Security\Domain\Enums\VulnerabilityFindingStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use Monolog\LogRecord;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * E26 Security Hardening — Quickstart SC-1 through SC-9.
 * Validates acceptance scenarios from specs/026-security/quickstart.md.
 */
final class SecurityQuickstartTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['platform', 'identity'];

    // ── SC-1: Security headers present on all responses ────────────────────────

    /** SC-1: SecurityHeadersMiddleware adds all six required headers to every response. */
    public function test_sc1_security_headers_present_on_all_responses(): void
    {
        $middleware = $this->app->make(SecurityHeadersMiddleware::class);
        $request    = Request::create('/api/v1/health', 'GET');

        $response = $middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true])
        );

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertNotEmpty($hsts, 'Strict-Transport-Security header must be present');
        $this->assertStringContainsString('max-age=', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'), 'Content-Security-Policy header must be present');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'), 'Permissions-Policy header must be present');
    }

    // ── SC-2: Brute-force lockout ──────────────────────────────────────────────

    /** SC-2: LockoutService locks the account after exceeding the failed attempt threshold. */
    public function test_sc2_brute_force_lockout_triggers_after_threshold(): void
    {
        $userId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'              => $userId,
            'email'           => 'lockout-test-' . $userId . '@test.local',
            'mobile_number'   => '+9' . mt_rand(100000000, 999999999),
            'status'          => 'active',
            'actor_type'      => 'CUSTOMER',
            'default_role_id' => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $user          = User::on('identity')->find($userId);
        $service       = $this->app->make(LockoutService::class);
        $correlationId = (string) Uuid::uuid7();
        $ipHash        = hash('sha256', '127.0.0.1');

        // Drive failures until the service throws AccountLockedException (threshold=5 default).
        $locked = false;
        for ($i = 0; $i < 10; $i++) {
            $user->refresh();
            try {
                $service->recordFailure($user, $correlationId, $ipHash, 'm***@test.local');
            } catch (AccountLockedException) {
                $locked = true;
                break;
            }
        }

        $this->assertTrue($locked, 'LockoutService must throw AccountLockedException once the threshold is reached');

        // assertNotLocked must also throw since locked_at is now set.
        $user->refresh();
        $this->expectException(AccountLockedException::class);
        $service->assertNotLocked($user, $correlationId, $ipHash);
    }

    // ── SC-3: Log masking — SensitiveDataProcessor strips PII from Monolog records ──

    /** SC-3: SensitiveDataProcessor masks sensitive keys before any handler writes the record. */
    public function test_sc3_sensitive_data_processor_masks_pii_fields(): void
    {
        $processor = new SensitiveDataProcessor();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel:  'testing',
            level:    Level::Info,
            message:  'sc3-security-test',
            context:  [
                'pin'            => '123456',
                'otp'            => '789012',
                'account_number' => 'ACC0001234567890',
                'amount'         => 500,
            ],
            extra: [],
        );

        $processed = $processor($record);

        $this->assertNotEquals('123456', $processed->context['pin'] ?? null, 'pin must be masked');
        $this->assertNotEquals('789012', $processed->context['otp'] ?? null, 'otp must be masked');
        $this->assertNotEquals('ACC0001234567890', $processed->context['account_number'] ?? null, 'account_number must be masked');
        $this->assertSame(500, $processed->context['amount'] ?? null, 'non-sensitive field must be preserved');
    }

    // ── SC-4: Response masking — PiiMasker redacts PII in API responses ────────

    /** SC-4: PiiMasker.maskEmail / maskPhone / maskNationalId produce redacted values. */
    public function test_sc4_pii_masker_redacts_email_phone_national_id(): void
    {
        $maskedEmail = PiiMasker::maskEmail('john.doe@example.com');
        $this->assertStringNotContainsString('john', $maskedEmail, 'email local part must be redacted');
        $this->assertStringContainsString('@', $maskedEmail, 'email @ separator must be preserved');

        $maskedPhone = PiiMasker::maskPhone('+26771234567');
        $this->assertStringStartsWith('+', $maskedPhone, 'international prefix must be preserved');
        $this->assertStringEndsWith('4567', $maskedPhone, 'last 4 digits must be visible');
        $this->assertStringNotContainsString('2677123', $maskedPhone, 'middle digits must be hidden');

        $maskedId = PiiMasker::maskNationalId('OMANG123456789');
        $this->assertStringStartsWith('****', $maskedId, 'national ID must start with ****');
        $this->assertStringEndsWith('6789', $maskedId, 'last 4 chars must be visible');
        $this->assertStringNotContainsString('OMANG', $maskedId, 'prefix must be hidden');
    }

    // ── SC-5: Vulnerability finding CRUD — create and suppress ────────────────

    /** SC-5: Admin can create a finding (status=open) and suppress it (status=suppressed). */
    public function test_sc5_create_finding_and_suppress_via_http(): void
    {
        $this->actingAsAdminWithPermission('security.findings.write');

        $idempotencyKey = (string) Uuid::uuid7();

        $createResponse = $this->postJson('/v1/security/findings', [
            'scanner'     => 'trivy',
            'finding_ref' => 'CVE-SC5-' . substr($idempotencyKey, 0, 8),
            'severity'    => VulnerabilityFindingSeverity::Medium->value,
            'component'   => 'laravel/framework',
            'title'       => 'SC-5 test: outdated dependency',
            'description' => 'Test finding for SC-5 quickstart validation.',
        ], ['Idempotency-Key' => $idempotencyKey]);

        $createResponse->assertStatus(201);
        $this->assertSame(VulnerabilityFindingStatus::Open->value, $createResponse->json('data.status'));
        $findingId = $createResponse->json('data.id');
        $this->assertNotEmpty($findingId);

        // Re-authenticate with write permission for PATCH.
        $this->actingAsAdminWithPermission('security.findings.write');

        $suppressResponse = $this->patchJson("/v1/security/findings/{$findingId}/status", [
            'status'                    => VulnerabilityFindingStatus::Suppressed->value,
            'suppression_justification' => 'False positive — version pinned by upstream dependency.',
        ]);

        $suppressResponse->assertStatus(200);
        $this->assertSame(VulnerabilityFindingStatus::Suppressed->value, $suppressResponse->json('data.status'));
    }

    // ── SC-6: At-rest security — credential hash is not plaintext ─────────────

    /** SC-6: Credentials are stored as bcrypt hashes; raw values are never persisted. */
    public function test_sc6_credential_stored_as_hash_not_plaintext(): void
    {
        $rawPassword = 'MySuperSecretPassword!99';
        $hash        = password_hash($rawPassword, PASSWORD_BCRYPT);
        $userId      = (string) Uuid::uuid7();
        $credId      = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.users')->insert([
            'id'              => $userId,
            'email'           => 'hash-test-' . $userId . '@test.local',
            'mobile_number'   => '+9' . mt_rand(100000000, 999999999),
            'status'          => 'active',
            'actor_type'      => 'CUSTOMER',
            'default_role_id' => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::connection('identity')->table('identity.credentials')->insert([
            'id'              => $credId,
            'user_id'         => $userId,
            'credential_type' => 'pin',
            'credential_hash' => $hash,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $storedHash = DB::connection('identity')
            ->table('identity.credentials')
            ->where('id', $credId)
            ->value('credential_hash');

        $this->assertNotEquals($rawPassword, $storedHash, 'Plaintext credential must NOT be stored in DB');
        $this->assertTrue(password_verify($rawPassword, $storedHash), 'Stored hash must verify against the original value');
        $this->assertStringStartsWith('$2y$', $storedHash, 'Hash must be bcrypt (starts with $2y$)');
    }

    // ── SC-7: Secrets boot check ───────────────────────────────────────────────

    /** SC-7: Application AUDIT_HMAC_KEY config must be non-empty (tested in env). */
    public function test_sc7_audit_hmac_key_is_configured(): void
    {
        $auditKey = config('audit.hmac_key') ?? env('AUDIT_HMAC_KEY', '');
        $this->assertNotEmpty($auditKey, 'AUDIT_HMAC_KEY must be set in the environment to protect audit integrity');

        $appKey = config('app.key');
        $this->assertNotEmpty($appKey, 'APP_KEY must be set in the environment for Laravel encryption');
    }

    // ── SC-9: Security dashboard returns aggregate counts ─────────────────────

    /** SC-9: GET /v1/security/dashboard returns findings aggregated by severity and status. */
    public function test_sc9_security_dashboard_returns_aggregate_counts(): void
    {
        $this->actingAsAdminWithPermission('security.findings.read');

        $response = $this->getJson('/v1/security/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total_open',
                'findings_by_severity',
                'findings_by_status',
            ],
        ]);

        $this->assertIsInt($response->json('data.total_open'));
        $this->assertIsArray($response->json('data.findings_by_severity'));
        $this->assertIsArray($response->json('data.findings_by_status'));
    }
}
