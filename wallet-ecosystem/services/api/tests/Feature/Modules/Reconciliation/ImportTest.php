<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reconciliation;

use App\Modules\Reconciliation\Application\Jobs\ProcessReconciliationBatchJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T039 — E20 Reconciliation import feature tests (Docker-gated).
 *
 * Run: DB_DATABASE=wallet_ecosystem_test php artisan test tests/Feature/Modules/Reconciliation/ImportTest.php
 */
final class ImportTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string> */
    protected $connectionsToTransact = [
        'platform', 'identity', 'profile', 'wallet', 'ledger', 'kyc',
        'transaction', 'risk', 'agent', 'notification', 'funding', 'transfer',
        'reconciliation',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('reconciliation_imports');
        Queue::fake();
    }

    private function makeCsvFile(?string $content = null): UploadedFile
    {
        $csv = $content ?? "provider_reference,amount,currency_code,transaction_date\n"
            . "REF001,100.0000,BWP,2026-09-25\n"
            . "REF002,200.0000,BWP,2026-09-25\n"
            . "REF003,300.0000,BWP,2026-09-25\n";

        $path = tempnam(sys_get_temp_dir(), 'recon_csv_');
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'transactions.csv', 'text/csv', null, true);
    }

    private function filePayload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'batch_date'      => '2026-09-25',
        ], $overrides);
    }

    private function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'batch_date'      => '2026-09-25',
            'batch_id'        => (string) Str::uuid(),
            'transactions'    => [
                [
                    'provider_reference' => 'REF001',
                    'amount'             => '100.0000',
                    'currency_code'      => 'BWP',
                    'transaction_date'   => '2026-09-25',
                ],
            ],
        ], $overrides);
    }

    private function webhookPost(array $payload, string $secret): \Illuminate\Testing\TestResponse
    {
        $body      = json_encode($payload);
        $signature = hash_hmac('sha256', (string) $body, $secret);

        return $this->withHeaders(['X-Provider-Signature' => $signature])
            ->postJson('/v1/reconciliation/batches/api', $payload);
    }

    // ── SC-1: CSV upload → 202; batch total_count=3 ──────────────────────────

    public function test_sc1_admin_csv_upload_returns_202_with_correct_count(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $response = $this->actingAs($admin)
            ->post('/v1/reconciliation/batches/file',
                array_merge($this->filePayload(), ['file' => $this->makeCsvFile()]));

        $response->assertStatus(202)
            ->assertJsonPath('data.total_count', 3)
            ->assertJsonPath('data.source_type', 'provider_file');

        Queue::assertPushed(ProcessReconciliationBatchJob::class);
    }

    // ── SC-2: duplicate idempotency_key → 202; same batch ID returned ─────────

    public function test_sc2_duplicate_idempotency_key_returns_same_batch_id(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $idemKey = (string) Str::uuid();
        $payload = $this->filePayload(['idempotency_key' => $idemKey]);

        $first = $this->actingAs($admin)
            ->post('/v1/reconciliation/batches/file',
                array_merge($payload, ['file' => $this->makeCsvFile()]));
        $first->assertStatus(202);
        $batchId = $first->json('data.id');

        $second = $this->actingAs($admin)
            ->post('/v1/reconciliation/batches/file',
                array_merge($payload, ['file' => $this->makeCsvFile()]));
        $second->assertStatus(202)
            ->assertJsonPath('data.id', $batchId);
    }

    // ── Validation: invalid file type → 422 ──────────────────────────────────

    public function test_invalid_file_type_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $badPath = tempnam(sys_get_temp_dir(), 'recon_pdf_');
        file_put_contents($badPath, '%PDF-1.4 fake content');
        $badFile = new UploadedFile($badPath, 'data.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($admin)
            ->post('/v1/reconciliation/batches/file',
                array_merge($this->filePayload(), ['file' => $badFile]));

        $response->assertStatus(422);
    }

    // ── Validation: missing file → 422 ───────────────────────────────────────

    public function test_missing_file_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/reconciliation/batches/file', $this->filePayload());

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ── Validation: missing idempotency_key → 422 ────────────────────────────

    public function test_missing_idempotency_key_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $response = $this->actingAs($admin)
            ->post('/v1/reconciliation/batches/file', [
                'batch_date' => '2026-09-25',
                'file'       => $this->makeCsvFile(),
            ]);

        $response->assertStatus(422);
    }

    // ── Validation: missing batch_date → 422 ─────────────────────────────────

    public function test_missing_batch_date_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $response = $this->actingAs($admin)
            ->post('/v1/reconciliation/batches/file', [
                'idempotency_key' => (string) Str::uuid(),
                'file'            => $this->makeCsvFile(),
            ]);

        $response->assertStatus(422);
    }

    // ── Auth: no token → 401 ─────────────────────────────────────────────────

    public function test_unauthenticated_file_upload_returns_401(): void
    {
        $response = $this->post('/v1/reconciliation/batches/file',
            array_merge($this->filePayload(), ['file' => $this->makeCsvFile()]));

        $response->assertStatus(401);
    }

    // ── Auth: customer without permission → 403 ──────────────────────────────

    public function test_customer_without_permission_returns_403(): void
    {
        $customer = $this->createAuthenticatedUser([]);

        $response = $this->actingAs($customer)
            ->post('/v1/reconciliation/batches/file',
                array_merge($this->filePayload(), ['file' => $this->makeCsvFile()]));

        $response->assertStatus(403);
    }

    // ── API endpoint: POST /batches/api → 202 ────────────────────────────────

    public function test_api_receive_returns_202(): void
    {
        $secret = 'test-webhook-' . Str::random(8);
        Config::set('reconciliation.webhook_secret', $secret);

        $payload = $this->apiPayload();

        $response = $this->webhookPost($payload, $secret);

        $response->assertStatus(202)
            ->assertJsonPath('data.source_type', 'provider_api')
            ->assertJsonPath('data.total_count', 1);

        Queue::assertPushed(ProcessReconciliationBatchJob::class);
    }

    // ── API endpoint: duplicate idempotency_key → 202 same batch ID ──────────

    public function test_api_duplicate_idempotency_returns_same_batch_id(): void
    {
        $secret  = 'test-webhook-' . Str::random(8);
        Config::set('reconciliation.webhook_secret', $secret);

        $payload = $this->apiPayload();

        $first = $this->webhookPost($payload, $secret);
        $first->assertStatus(202);
        $firstId = $first->json('data.id');

        $second = $this->webhookPost($payload, $secret);
        $second->assertStatus(202)
            ->assertJsonPath('data.id', $firstId);
    }

    // ── API endpoint: invalid HMAC signature → 401 ───────────────────────────

    public function test_api_invalid_hmac_returns_401(): void
    {
        $secret = 'test-webhook-' . Str::random(8);
        Config::set('reconciliation.webhook_secret', $secret);

        $response = $this->withHeaders(['X-Provider-Signature' => 'invalid-sig'])
            ->postJson('/v1/reconciliation/batches/api', $this->apiPayload());

        $response->assertStatus(401);
    }

    // ── API endpoint: missing signature header → 401 ─────────────────────────

    public function test_api_missing_signature_header_returns_401(): void
    {
        $secret = 'test-webhook-' . Str::random(8);
        Config::set('reconciliation.webhook_secret', $secret);

        $response = $this->postJson('/v1/reconciliation/batches/api', $this->apiPayload());

        $response->assertStatus(401);
    }
}
