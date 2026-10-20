<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support;

use Tests\Support\FakeRequirePermissionMiddleware;
use App\Modules\Support\Domain\Exceptions\DisputeEvidenceLimitException;
use App\Modules\Support\Infrastructure\Models\DisputeEvidenceModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Support\Concerns\CreatesDisputeFixtures;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\PlatformTestCase;

final class DisputeEvidenceTest extends PlatformTestCase
{
    use CreatesDisputeFixtures;

    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
        Storage::fake('support-evidence');
    }

    public function test_attaching_evidence_to_raised_dispute_transitions_to_evidence_submitted(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');
        $file       = UploadedFile::fake()->create('receipt.png', 100, 'image/png');

        $response = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true)['data'];
        $this->assertNotEmpty($data['id']);
        $this->assertSame($dispute->id, $data['dispute_id']);
    }

    public function test_sixth_evidence_file_returns_422(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');

        // Seed 5 evidence records directly
        for ($i = 0; $i < 5; $i++) {
            $ev               = new DisputeEvidenceModel();
            $ev->id           = (string) Uuid::uuid7();
            $ev->dispute_id   = $dispute->id;
            $ev->uploaded_by    = $customerId;
            $ev->file_type    = 'image_png';
            $ev->file_key     = "support-evidence/{$dispute->id}/{$ev->id}.png";
            $ev->original_name = "evidence-{$i}.png";
            $ev->size_bytes   = 1024;
            $ev->created_at   = now();
            $ev->save();
        }

        $file = UploadedFile::fake()->create('sixth.png', 100, 'image/png');

        $response = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('DISPUTE_EVIDENCE_LIMIT_REACHED', $body['error']['code'] ?? null);
    }

    public function test_oversized_file_returns_422(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');
        $file       = UploadedFile::fake()->create('large.pdf', 11_000, 'application/pdf');

        $response = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_wrong_mime_type_returns_422(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');
        $file       = UploadedFile::fake()->create('script.js', 10, 'application/javascript');

        $response = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_evidence_locked_when_dispute_under_investigation(): void
    {
        $customerId = (string) Uuid::uuid7();
        $dispute    = $this->createDispute((string) Uuid::uuid7(), $customerId, 'under_investigation');
        $file       = UploadedFile::fake()->create('receipt.png', 100, 'image/png');

        $response = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('EVIDENCE_LOCKED', $body['error']['code'] ?? null);
    }

    public function test_attach_evidence_is_idempotent(): void
    {
        $customerId     = (string) Uuid::uuid7();
        $dispute        = $this->createDispute((string) Uuid::uuid7(), $customerId, 'raised');
        $idempotencyKey = (string) Uuid::uuid7();

        $file1 = UploadedFile::fake()->create('receipt.png', 100, 'image/png');
        $file2 = UploadedFile::fake()->create('receipt2.png', 100, 'image/png');

        $r1 = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file1], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => $idempotencyKey,
        ]);
        $r2 = $this->call('POST', "/v1/disputes/{$dispute->id}/evidence", [], [], ['file' => $file2], [
            'HTTP_X-Test-User-Id'     => $customerId,
            'HTTP_X-Test-Actor-Type'  => 'customer',
            'HTTP_X-Test-Permissions' => 'dispute.raise',
            'HTTP_Idempotency-Key'    => $idempotencyKey,
        ]);

        $body1 = json_decode($r1->getContent(), true);
        $body2 = json_decode($r2->getContent(), true);

        $this->assertSame(201, $r1->getStatusCode());
        $this->assertSame(200, $r2->getStatusCode());
        $this->assertSame($body1['data']['id'], $body2['data']['id']);
    }
}
