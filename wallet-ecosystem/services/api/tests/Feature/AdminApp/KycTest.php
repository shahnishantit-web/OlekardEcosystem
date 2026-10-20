<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class KycTest extends TestCase
{
    public function test_list_kyc_queue_returns_pending_submissions(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.kyc.review')
            ->getJson('/v1/admin-app/kyc?status=pending_review');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['kyc_submission_id', 'user_id', 'status', 'requested_tier']],
                'meta' => ['total', 'per_page'],
            ]);
    }

    public function test_approve_kyc_returns_202_with_approval_id(): void
    {
        $kycId = $this->createKycSubmissionInReview();

        $response = $this->actingAsAdminWithPermission('adminapp.kyc.approve')
            ->withHeaders(['Idempotency-Key' => 'approve-kyc-' . $kycId])
            ->postJson("/v1/admin-app/kyc/{$kycId}/approve");

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => ['approval_id', 'action_type', 'status', 'expires_at'],
            ])
            ->assertJsonPath('data.action_type', 'adminapp.kyc.approve')
            ->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_approve_non_pending_kyc_returns_422(): void
    {
        $kycId = $this->createKycSubmissionApproved();

        $response = $this->actingAsAdminWithPermission('adminapp.kyc.approve')
            ->withHeaders(['Idempotency-Key' => 'approve-kyc-2-' . $kycId])
            ->postJson("/v1/admin-app/kyc/{$kycId}/approve");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ADMINAPP_KYC_002');
    }

    public function test_operations_role_denied_kyc_approve(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.users.read')
            ->withHeaders(['Idempotency-Key' => 'test-idem-kyc'])
            ->postJson('/v1/admin-app/kyc/some-id/approve');

        $response->assertStatus(403);
    }

    public function test_checker_can_approve_kyc_pending_action(): void
    {
        $kycId     = $this->createKycSubmissionInReview();
        $makerId   = 'maker-user-id';
        $checkerId = 'checker-user-id';

        $makerResponse = $this->actingAsUserWithIdAndPermission($makerId, 'adminapp.kyc.approve')
            ->withHeaders(['Idempotency-Key' => 'kyc-approve-maker-' . $kycId])
            ->postJson("/v1/admin-app/kyc/{$kycId}/approve");

        $makerResponse->assertStatus(202);
        $approvalId = $makerResponse->json('data.approval_id');

        $checkerResponse = $this->actingAsUserWithIdAndPermission($checkerId, 'adminapp.kyc.approve')
            ->withHeaders(['Idempotency-Key' => 'kyc-checker-approve-' . $approvalId])
            ->postJson("/v1/admin-app/approvals/{$approvalId}/approve");

        $checkerResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');
    }

    private function createKycSubmissionInReview(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    private function createKycSubmissionApproved(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }
}
