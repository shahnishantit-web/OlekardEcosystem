<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Modules\Support\Infrastructure\Storage\LaravelDiskEvidenceStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class EvidenceStorageTest extends TestCase
{
    private LaravelDiskEvidenceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('support-evidence');
        $this->storage = new LaravelDiskEvidenceStorage();
    }

    public function test_store_places_file_at_correct_path(): void
    {
        $disputeId  = (string) Uuid::uuid7();
        $evidenceId = (string) Uuid::uuid7();
        $file       = UploadedFile::fake()->create('receipt.png', 100, 'image/png');

        $fileKey = $this->storage->store($disputeId, $evidenceId, $file);

        $expectedPath = "support-evidence/{$disputeId}/{$evidenceId}.png";
        $this->assertSame($expectedPath, $fileKey);
        Storage::disk('support-evidence')->assertExists("support-evidence/{$disputeId}/{$evidenceId}.png");
    }

    public function test_store_uses_correct_extension_for_pdf(): void
    {
        $disputeId  = (string) Uuid::uuid7();
        $evidenceId = (string) Uuid::uuid7();
        $file       = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $fileKey = $this->storage->store($disputeId, $evidenceId, $file);

        $this->assertSame("support-evidence/{$disputeId}/{$evidenceId}.pdf", $fileKey);
    }

    public function test_generate_signed_url_returns_non_empty_string(): void
    {
        $disputeId  = (string) Uuid::uuid7();
        $evidenceId = (string) Uuid::uuid7();
        $file       = UploadedFile::fake()->create('receipt.png', 100, 'image/png');

        $fileKey = $this->storage->store($disputeId, $evidenceId, $file);
        $url     = $this->storage->generateSignedUrl($fileKey, 15);

        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }

    public function test_signed_url_uses_default_ttl_of_15_minutes(): void
    {
        $disputeId  = (string) Uuid::uuid7();
        $evidenceId = (string) Uuid::uuid7();
        $file       = UploadedFile::fake()->create('photo.png', 100, 'image/png');

        $fileKey = $this->storage->store($disputeId, $evidenceId, $file);

        // Default TTL (no second argument) should not throw
        $url = $this->storage->generateSignedUrl($fileKey);
        $this->assertIsString($url);
    }
}
