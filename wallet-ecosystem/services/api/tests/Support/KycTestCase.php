<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;
use App\Modules\Kyc\Infrastructure\Models\KycDocumentModel;
use App\Modules\Kyc\Infrastructure\Models\KycPersonalInfoModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * Base test class for KYC module feature, security, integration, and concurrency tests.
 *
 * Extends IdentityTestCase (provides FakeRedisConnection injection and
 * DatabaseTransactions on null + identity connections).
 *
 * Adds:
 * - FakeIdentityAuthMiddleware override so routes don't need a real JWT
 * - Storage::fake('kyc-documents') so document uploads don't touch disk
 * - Helper methods for creating actors with DB-backed permissions
 */
abstract class KycTestCase extends IdentityTestCase
{
    /** @var string[] */
    protected $connectionsToTransact = [null, 'identity', 'kyc'];

    protected function setUp(): void
    {
        parent::setUp();

        // Override identity.auth to read identity claims from test headers instead of JWT
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        Storage::fake('kyc-documents');
    }

    // ── Actor factory helpers ─────────────────────────────────────────────────

    /**
     * Create a CUSTOMER user in identity.users with DB-seeded permissions.
     * The returned object carries .id, .email, .actor_type.
     *
     * @param  string ...$permissions  Permission slugs to assign (e.g. 'kyc.case.create')
     */
    protected function makeCustomerWithPermission(string ...$permissions): object
    {
        return $this->makeActorWithPermissions('CUSTOMER', ...$permissions);
    }

    /**
     * Create a CUSTOMER user with NO permissions.
     */
    protected function makeCustomerWithoutPermissions(): object
    {
        return $this->makeActorWithPermissions('CUSTOMER');
    }

    /**
     * Create an ADMIN user with the given permissions.
     */
    protected function makeAdminWithPermission(string ...$permissions): object
    {
        return $this->makeActorWithPermissions('ADMIN', ...$permissions);
    }

    /**
     * Return a test instance pre-loaded with the X-Test-User-Id / X-Test-Actor-Type headers
     * needed by FakeIdentityAuthMiddleware.
     */
    protected function withCustomerToken(object $user, array $extraHeaders = []): static
    {
        return $this->withHeaders(array_merge([
            'X-Test-User-Id'    => $user->id,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ], $extraHeaders));
    }

    /**
     * Generic token helper that uses the actor_type stored on the user object.
     */
    protected function withActorToken(object $user, array $extraHeaders = []): static
    {
        return $this->withHeaders(array_merge([
            'X-Test-User-Id'    => $user->id,
            'X-Test-Actor-Type' => $user->actor_type,
        ], $extraHeaders));
    }

    // ── KYC data helpers ─────────────────────────────────────────────────────

    protected function kycCreateCase(string $userId, ?string $idempotencyKey = null): KycCaseModel
    {
        $id = (string) Uuid::uuid7();
        DB::connection('kyc')->table('kyc.kyc_cases')->insert([
            'id'              => $id,
            'user_id'         => $userId,
            'status'          => 'initiated',
            'attempt_number'  => 1,
            'idempotency_key' => $idempotencyKey ?? $id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return KycCaseModel::on('kyc')->find($id);
    }

    protected function kycAddPersonalInfo(string $caseId, string $nationalIdHash): KycPersonalInfoModel
    {
        $id = (string) Uuid::uuid7();
        DB::connection('kyc')->table('kyc.kyc_personal_info')->insert([
            'id'               => $id,
            'kyc_case_id'      => $caseId,
            'full_legal_name'  => 'Test User',
            'date_of_birth'    => '1990-01-01',
            'nationality'      => 'BW',
            'national_id_type' => 'national_id',
            'national_id_hash' => $nationalIdHash,
            'address_line_1'   => '123 Test Street',
            'city'             => 'Gaborone',
            'postal_code'      => '0001',
            'country_code'     => 'BW',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return KycPersonalInfoModel::on('kyc')->find($id);
    }

    protected function kycUploadDocument(string $caseId, string $documentType = 'national_id'): KycDocumentModel
    {
        $id = (string) Uuid::uuid7();
        DB::connection('kyc')->table('kyc.kyc_documents')->insert([
            'id'                => $id,
            'kyc_case_id'       => $caseId,
            'document_type'     => $documentType,
            'file_reference'    => "kyc-documents/{$caseId}/test-doc.jpg",
            'mime_type'         => 'image/jpeg',
            'file_size_bytes'   => 102400,
            'expires_on'        => now()->addYears(5)->format('Y-m-d'),
            'validation_status' => 'valid',
            'validation_error'  => null,
            'created_at'        => now(),
        ]);

        return KycDocumentModel::on('kyc')->find($id);
    }

    protected function kycSubmitCase(string $caseId): void
    {
        DB::connection('kyc')->table('kyc.kyc_cases')
            ->where('id', $caseId)
            ->update([
                'status'       => 'under_review',
                'submitted_at' => now(),
                'updated_at'   => now(),
            ]);
    }

    // ── Private factory ───────────────────────────────────────────────────────

    /**
     * Create an actor in identity.users with a freshly seeded role + permissions.
     *
     * Steps:
     *  1. If permissions requested: create a unique role, insertOrIgnore each
     *     permission slug into identity.permissions, assign via role_permissions.
     *  2. Create a user with default_role_id pointing at that role (or null if none).
     */
    protected function makeActorWithPermissions(string $actorType, string ...$permissions): object
    {
        $roleId = null;

        if (!empty($permissions)) {
            $roleId = (string) Uuid::uuid7();

            DB::connection('identity')->table('identity.roles')->insert([
                'id'             => $roleId,
                'slug'           => 'test-role-' . $roleId,
                'display_name'   => 'Test Role',
                'is_system_role' => false,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            foreach ($permissions as $slug) {
                // Seed permission if not already present (KYC migration may have run)
                DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                    'id'                   => (string) Uuid::uuid7(),
                    'slug'                 => $slug,
                    'display_name'         => $slug,
                    'module'               => explode('.', $slug)[0] ?? 'kyc',
                    'is_system_permission' => false,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

                $perm = DB::connection('identity')
                    ->table('identity.permissions')
                    ->where('slug', $slug)
                    ->first(['id']);

                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) Uuid::uuid7(),
                    'role_id'       => $roleId,
                    'permission_id' => $perm->id,
                    'created_at'    => now(),
                ]);
            }
        }

        $userId = (string) Uuid::uuid7();
        $email  = 'kyc-test-' . $userId . '@test.local';
        $mobile = '+9' . mt_rand(100000000, 999999999);

        DB::connection('identity')->table('identity.users')->insert([
            'id'              => $userId,
            'email'           => $email,
            'mobile_number'   => $mobile,
            'status'          => 'active',
            'actor_type'      => $actorType,
            'default_role_id' => $roleId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return (object) [
            'id'         => $userId,
            'email'      => $email,
            'actor_type' => $actorType,
        ];
    }
}
