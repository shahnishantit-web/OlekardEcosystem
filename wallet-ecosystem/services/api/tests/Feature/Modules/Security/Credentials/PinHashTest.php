<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Credentials;

use App\Modules\Identity\Infrastructure\Models\Credential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * T075 — For every credential type that stores a credential_hash, assert the stored
 * value is a bcrypt/argon2 hash and not plaintext.
 *
 * Credential types that use credential_hash: pin, password, mfa_totp.
 * Credential types that do NOT use credential_hash: biometric_binding, mfa_fido2 (use public-key fields).
 *
 * FR-008: All passwords, PINs, and OTP secrets MUST be stored as one-way hashes — never plaintext.
 */
final class PinHashTest extends TestCase
{
    use RefreshDatabase;

    private function seedCredential(string $type, string $plaintext): Credential
    {
        $userId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $userId,
            'email'         => "test-{$userId}@example.com",
            'display_name'  => 'Test User',
            'phone_number'  => '+1000000' . random_int(1000, 9999),
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $hash = Hash::make($plaintext);

        DB::connection('identity')->table('identity.credentials')->insert([
            'id'              => (string) Uuid::uuid7(),
            'user_id'         => $userId,
            'credential_type' => $type,
            'credential_hash' => $hash,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return Credential::on('identity')
            ->where('user_id', $userId)
            ->where('credential_type', $type)
            ->firstOrFail();
    }

    private function assertStoredAsHash(Credential $credential, string $plaintext): void
    {
        $raw = DB::connection('identity')
            ->table('identity.credentials')
            ->where('id', $credential->id)
            ->value('credential_hash');

        $this->assertNotNull($raw, "credential_hash should not be null for type [{$credential->credential_type}]");

        // Must start with a bcrypt prefix ($2y$) or argon2id prefix ($argon2)
        $this->assertTrue(
            str_starts_with((string) $raw, '$2y$') || str_starts_with((string) $raw, '$argon2'),
            "credential_hash for type [{$credential->credential_type}] must be a bcrypt or argon2 hash; got: " . substr((string) $raw, 0, 20)
        );

        // Must not be the plaintext value
        $this->assertNotEquals(
            $plaintext,
            $raw,
            "credential_hash for type [{$credential->credential_type}] must not store plaintext"
        );

        // Hash::check must verify successfully
        $this->assertTrue(
            Hash::check($plaintext, (string) $raw),
            "Hash::check must pass for type [{$credential->credential_type}]"
        );
    }

    /** @test */
    public function pin_credential_hash_is_stored_as_bcrypt_not_plaintext(): void
    {
        $plainPin = '123456';
        $credential = $this->seedCredential('pin', $plainPin);
        $this->assertStoredAsHash($credential, $plainPin);
    }

    /** @test */
    public function password_credential_hash_is_stored_as_bcrypt_not_plaintext(): void
    {
        $plainPassword = 'SecureP@ssword1!';
        $credential = $this->seedCredential('password', $plainPassword);
        $this->assertStoredAsHash($credential, $plainPassword);
    }

    /** @test */
    public function mfa_totp_credential_hash_is_stored_as_bcrypt_not_plaintext(): void
    {
        $totpSecret = 'JBSWY3DPEHPK3PXP';
        $credential = $this->seedCredential('mfa_totp', $totpSecret);
        $this->assertStoredAsHash($credential, $totpSecret);
    }

    /** @test */
    public function credential_hash_is_not_exposed_in_model_serialization(): void
    {
        $credential = $this->seedCredential('pin', '654321');
        $array      = $credential->toArray();

        $this->assertArrayNotHasKey(
            'credential_hash',
            $array,
            'credential_hash must be hidden from model serialization to prevent hash exposure'
        );
    }
}
