<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Payment\Application\Services\PayoutBeneficiaryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Local-only complete Funeral Assistance collection fixture for Patel Funeral Community. */
final class DevPatelFuneralDemoSeeder extends Seeder
{
    private function stableId(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/patel-funeral-demo/'.$name)->toString();
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DevPatelFuneralDemoSeeder only runs in local/testing.');
        }

        // Provision the requested local workflow users, their module grants, and ten
        // customer accounts (PIN 123456; phone numbers +26771000011 through +20).
        $this->call([
            DevEventWorkflowUsersSeeder::class,
            DevFuneralWorkflowPermissionsSeeder::class,
            DevTenCustomerAppSeeder::class,
        ]);

        $identity = DB::connection('identity');
        $this->grantCustomerObligationRead($identity);
        $platform = DB::connection('platform');
        $services = DB::connection('community_services');
        $creatorId = (string) $identity->table('identity.users')->where('email', 'event.creator@olekard.local')->value('id');
        $reviewerId = (string) $identity->table('identity.users')->where('email', 'event.reviewer@olekard.local')->value('id');
        $approverId = (string) $identity->table('identity.users')->where('email', 'event.approver@olekard.local')->value('id');
        if ($creatorId === '' || $reviewerId === '' || $approverId === '') {
            throw new \RuntimeException('The creator, reviewer, and approver accounts must exist before provisioning the demo.');
        }

        $setting = $platform->table('platform.configuration_values')
            ->where('config_key', 'community_funeral.http_enabled')->where('status', 'active')
            ->orderByDesc('version')->value('config_value');
        $decodedSetting = is_string($setting) ? json_decode($setting, true) : null;
        if (($decodedSetting['value'] ?? false) !== true) {
            throw new \RuntimeException('Enable and approve community_funeral.http_enabled in Admin Configuration before running this demo seeder.');
        }

        $organisationReference = 'local-patel-funeral-community';
        $organisationId = $identity->table('identity.organisations')->where('registry_reference', $organisationReference)->value('id');
        $organisationId = $organisationId === null ? $this->stableId('organisation') : (string) $organisationId;
        $identity->table('identity.organisations')->updateOrInsert(['id' => $organisationId], [
            'registry_reference' => $organisationReference,
            'display_name' => 'Patel funeral Community',
            'status' => 'active',
            'evidence_reference' => 'local-demo://patel-funeral-community',
            'requested_by' => $creatorId,
            'reviewed_by' => $reviewerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordAuthorityEvent($identity, 'organisation', $organisationId, 'requested', $creatorId, null, 'pending_review');
        $this->recordAuthorityEvent($identity, 'organisation', $organisationId, 'reviewed', $reviewerId, 'pending_review', 'active');

        $members = [
            'creator' => $creatorId,
            'reviewer' => $reviewerId,
            'approver' => $approverId,
        ];
        for ($number = 1; $number <= 10; $number++) {
            $email = sprintf('demo.customer%02d@olekard.local', $number);
            $userId = $identity->table('identity.users')->where('email', $email)->value('id');
            if ($userId === null) {
                throw new \RuntimeException("Missing local customer {$email} after customer account provisioning.");
            }
            $members[sprintf('customer%02d', $number)] = (string) $userId;
        }

        foreach ($members as $key => $userId) {
            $membershipId = $identity->table('identity.organisation_memberships')
                ->where('organisation_id', $organisationId)->where('user_id', $userId)->value('id');
            $membershipId = $membershipId === null ? $this->stableId('membership/'.$key) : (string) $membershipId;
            $reviewedBy = $key === 'creator' ? $reviewerId : $approverId;
            $identity->table('identity.organisation_memberships')->updateOrInsert(['id' => $membershipId], [
                'organisation_id' => $organisationId,
                'user_id' => $userId,
                'status' => 'active',
                'evidence_reference' => 'local-demo://patel-funeral-membership/'.$key,
                'requested_by' => $creatorId,
                'reviewed_by' => $reviewedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordAuthorityEvent($identity, 'membership', $membershipId, 'requested', $creatorId, null, 'pending_review');
            $this->recordAuthorityEvent($identity, 'membership', $membershipId, 'reviewed', $reviewedBy, 'pending_review', 'active');
        }

        $beneficiary = DB::connection('payment')->table('payment.payout_beneficiaries')
            ->where('organisation_id', $organisationId)
            ->where('account_holder_name', 'Patel Funeral Demo Beneficiary')->first();
        if ($beneficiary === null) {
            $service = app(PayoutBeneficiaryService::class);
            $beneficiary = $service->register([
                'organisation_id' => $organisationId,
                'account_type' => 'bank_account',
                'account_number' => '0000000001',
                'bank_code' => 'DEMO',
                'bank_name' => 'Local Demo Bank (simulated)',
                'account_holder_name' => 'Patel Funeral Demo Beneficiary',
                'currency_code' => 'BWP',
            ], $creatorId);
            $beneficiary = $service->recordVerification(
                (string) $beneficiary->id,
                'local-demo://patel-funeral-beneficiary-verified',
                $reviewerId,
            );
        } elseif ($beneficiary->status !== 'verified') {
            app(PayoutBeneficiaryService::class)->recordVerification(
                (string) $beneficiary->id,
                'local-demo://patel-funeral-beneficiary-verified',
                $reviewerId,
            );
        }

        $funeral = app(FuneralAssistanceService::class);
        $community = $services->table('community_services.funeral_communities')
            ->where('organisation_id', $organisationId)->where('display_name', 'Patel Funeral Community')->first();
        $community ??= (object) $funeral->createCommunity($organisationId, 'Patel Funeral Community', $creatorId);
        $communityId = (string) $community->id;
        $funeral->addCommunityStaff($communityId, $reviewerId, 'reviewer', $creatorId);

        for ($number = 1; $number <= 10; $number++) {
            $reference = sprintf('PATEL-HOUSEHOLD-%02d', $number);
            $household = $services->table('community_services.funeral_households')
                ->where('community_id', $communityId)->where('private_reference', $reference)->first();
            if ($household === null) {
                $household = (object) $funeral->createHousehold($communityId, $reference, $creatorId);
            }
            $householdId = (string) $household->id;
            if ($household->status === 'pending_review') {
                $funeral->reviewHousehold($householdId, 'eligible', null, $reviewerId);
            }

            $customerId = $members[sprintf('customer%02d', $number)];
            $representative = $services->table('community_services.funeral_representatives')
                ->where('household_id', $householdId)->where('actor_id', $customerId)->first();
            if ($representative === null) {
                $representative = (object) $funeral->addRepresentative(
                    $householdId,
                    $customerId,
                    sprintf('local-demo://patel-household-%02d-representative', $number),
                    $creatorId,
                );
            }
            if ($representative->status === 'unverified') {
                $funeral->verifyRepresentative($householdId, (string) $representative->id, $reviewerId);
            }
        }

        $evidence = 'local-demo://patel-funeral-collection-v1';
        $collection = $services->table('community_services.funeral_collections')
            ->where('community_id', $communityId)->where('evidence_reference', $evidence)->first();
        if ($collection === null) {
            $collection = (object) $funeral->createCollection(
                $communityId,
                (string) $beneficiary->id,
                (string) $services->table('community_services.funeral_households')->where('community_id', $communityId)->where('private_reference', 'PATEL-HOUSEHOLD-01')->value('id'),
                $evidence,
                $creatorId,
                'Provide funeral assistance contributions for eligible Patel Funeral Community households.',
            );
        } else {
            $services->table('community_services.funeral_collections')->where('id', $collection->id)->update([
                'purpose' => 'Provide funeral assistance contributions for eligible Patel Funeral Community households.',
                'updated_at' => now(),
            ]);
        }
        if ($collection->status === 'under_review') {
            $funeral->approveCollection((string) $collection->id, $reviewerId);
            $collection->status = 'approved';
        }
        if ($collection->status === 'approved') {
            $funeral->activateCollection((string) $collection->id, $reviewerId);
        }

        $obligations = $services->table('community_services.funeral_obligations')
            ->where('collection_id', $collection->id)->count();
        $this->command?->info('Patel Funeral Community demo is ready: 10 active member households, verified representatives, verified demo beneficiary, and an active collection.');
        $this->command?->line('Collection ID: '.$collection->id.'; obligations: '.$obligations.'; contribution: '.$funeral->defaultCurrency().' '.number_format($funeral->defaultAmountMinor() / 100, 2));
        $this->command?->line('Customer logins are +26771000011 through +26771000020 with PIN 123456 (email-to-phone order is customer01 to customer10).');
        $this->command?->warn('Beneficiary payout remains blocked by the unreleased shared P18 payout contract. No disbursement is fabricated by this demo seeder.');
    }

    private function recordAuthorityEvent($identity, string $type, string $subjectId, string $action, string $actorId, ?string $previous, string $next): void
    {
        $identity->table('identity.organisation_authority_events')->insertOrIgnore([
            'id' => $this->stableId('authority-event/'.$type.'/'.$subjectId.'/'.$action),
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'action' => $action,
            'actor_id' => $actorId,
            'previous_status' => $previous,
            'new_status' => $next,
            'created_at' => now(),
        ]);
    }

    private function grantCustomerObligationRead($identity): void
    {
        $roleId = $identity->table('identity.roles')->where('slug', 'customer')->whereNull('deleted_at')->value('id');
        if ($roleId === null) {
            throw new \RuntimeException('The customer role must exist before provisioning the Funeral Assistance demo.');
        }
        $permissionId = $identity->table('identity.permissions')->where('slug', 'community.funeral.household.read')->value('id');
        if ($permissionId === null) {
            $permissionId = $this->stableId('permission/community.funeral.household.read');
            $identity->table('identity.permissions')->insertOrIgnore([
                'id' => $permissionId,
                'slug' => 'community.funeral.household.read',
                'display_name' => 'Read Funeral Assistance Household Obligations',
                'module' => 'community',
                'is_system_permission' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionId = $identity->table('identity.permissions')->where('slug', 'community.funeral.household.read')->value('id');
        }
        $identity->table('identity.role_permissions')->insertOrIgnore([
            'id' => $this->stableId('role-permission/customer/community.funeral.household.read'),
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_by_actor_id' => $identity->table('identity.users')->where('email', 'event.creator@olekard.local')->value('id'),
            'created_at' => now(),
        ]);
        $customerIds = $identity->table('identity.users')->where('default_role_id', $roleId)->pluck('id');
        foreach ($customerIds as $customerId) {
            app(PermissionResolverService::class)->invalidateCache((string) $customerId);
        }
    }
}
