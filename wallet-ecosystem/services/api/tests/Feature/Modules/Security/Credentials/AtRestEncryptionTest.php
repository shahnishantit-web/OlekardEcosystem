<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Credentials;

use App\Modules\Kyc\Infrastructure\Models\KycDocumentModel;
use App\Modules\Kyc\Infrastructure\Models\KycPersonalInfoModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T027/T028 — Verify at-rest encryption for KYC fields.
 * SC-003: Sensitive PII fields must be stored as AES-256-CBC ciphertext.
 *
 * Note on Credential.credential_hash (T023): credential_hash uses bcrypt — a one-way
 * hash — which satisfies FR-008 for credentials. No `encrypted` cast is needed because
 * the raw hash is never recoverable (one-way). Tests for that are in PinHashTest.
 */
final class AtRestEncryptionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function kyc_personal_info_date_of_birth_is_stored_as_ciphertext(): void
    {
        $kycCaseId = $this->createKycCase();

        $model = new KycPersonalInfoModel();
        $model->id              = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $model->kyc_case_id     = $kycCaseId;
        $model->date_of_birth   = '1990-06-15';
        $model->save();

        $raw = DB::connection('kyc')
            ->table('kyc.kyc_personal_info')
            ->where('id', $model->id)
            ->value('date_of_birth');

        // Raw DB value must be ciphertext, not the plaintext date
        $this->assertNotEquals('1990-06-15', $raw, 'date_of_birth stored as plaintext — encryption cast not applied');
        $this->assertNotNull($raw, 'date_of_birth should not be null after save');
        // Ciphertext starts with Laravel Encrypter's base64-encoded JSON payload
        $this->assertStringStartsWith('ey', $raw, 'date_of_birth raw value should be Laravel-encrypted ciphertext');

        // Model attribute must decrypt back to the original value
        $loaded = KycPersonalInfoModel::find($model->id);
        $this->assertEquals('1990-06-15', $loaded->date_of_birth?->format('Y-m-d'));
    }

    /** @test */
    public function kyc_document_document_number_is_stored_as_ciphertext(): void
    {
        $kycCaseId = $this->createKycCase();

        $model = new KycDocumentModel();
        $model->id              = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $model->kyc_case_id     = $kycCaseId;
        $model->document_type   = 'passport';
        $model->document_number = 'A12345678';
        $model->file_name       = 'passport.jpg';
        $model->file_path       = '/uploads/passport.jpg';
        $model->file_size_bytes = 1024;
        $model->mime_type       = 'image/jpeg';
        $model->save();

        $raw = DB::connection('kyc')
            ->table('kyc.kyc_documents')
            ->where('id', $model->id)
            ->value('document_number');

        $this->assertNotEquals('A12345678', $raw, 'document_number stored as plaintext — encryption cast not applied');
        $this->assertStringStartsWith('ey', (string) $raw);

        $loaded = KycDocumentModel::find($model->id);
        $this->assertSame('A12345678', $loaded->document_number);
    }

    /** @test */
    public function kyc_document_extracted_data_is_stored_as_ciphertext(): void
    {
        $kycCaseId = $this->createKycCase();
        $payload   = ['name' => 'Alice Smith', 'dob' => '1990-06-15'];

        $model = new KycDocumentModel();
        $model->id              = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $model->kyc_case_id     = $kycCaseId;
        $model->document_type   = 'passport';
        $model->extracted_data  = $payload;
        $model->file_name       = 'passport.jpg';
        $model->file_path       = '/uploads/passport.jpg';
        $model->file_size_bytes = 1024;
        $model->mime_type       = 'image/jpeg';
        $model->save();

        $raw = DB::connection('kyc')
            ->table('kyc.kyc_documents')
            ->where('id', $model->id)
            ->value('extracted_data');

        $this->assertNotEquals(json_encode($payload), $raw, 'extracted_data stored as plaintext JSON — encryption cast not applied');
        $this->assertStringStartsWith('ey', (string) $raw);

        $loaded = KycDocumentModel::find($model->id);
        $this->assertSame($payload, $loaded->extracted_data);
    }

    private function createKycCase(): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        DB::connection('kyc')->table('kyc.kyc_cases')->insert([
            'id'         => $id,
            'user_id'    => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }
}
