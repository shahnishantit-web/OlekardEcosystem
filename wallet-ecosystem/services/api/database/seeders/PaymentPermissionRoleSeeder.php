<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * T149: Seeds the 9 payment.* permissions and assigns them to roles.
 *
 * Permissions are idempotent (upsert). Role assignments are skipped
 * gracefully when the target role does not yet exist in the DB.
 */
final class PaymentPermissionRoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'payment.intents.read',              'description' => 'View payment intents and their status'],
            ['slug' => 'payment.intents.manage',            'description' => 'Create and manage payment intents'],
            ['slug' => 'payment.refunds.request',           'description' => 'Request refunds on completed payments'],
            ['slug' => 'payment.refunds.approve',           'description' => 'Approve refund requests above the approval threshold'],
            ['slug' => 'payment.providers.read',            'description' => 'View payment provider configuration'],
            ['slug' => 'payment.providers.manage',          'description' => 'Create, update, enable, and disable payment providers'],
            ['slug' => 'payment.config.approve_high_risk',  'description' => 'Approve high-risk configuration changes (merchant account, settlement destination)'],
            ['slug' => 'payment.reconciliation.view',       'description' => 'View reconciliation exceptions and provider transaction records'],
            ['slug' => 'payment.reconciliation.manage',     'description' => 'Resolve and escalate reconciliation exceptions'],
            // P03 additions
            ['slug' => 'payment.config.draft',              'description' => 'Submit a high-risk provider configuration change for approval'],
            ['slug' => 'payment.payouts.view',              'description' => 'View payout records and history'],
            ['slug' => 'payment.payouts.manage',            'description' => 'Initiate and manage payout disbursements'],
            ['slug' => 'payment.callbacks.receive',         'description' => 'System-level: authorise the callback handler processing path'],
            ['slug' => 'payment.exports.view',              'description' => 'Export payment transaction data for audit and compliance'],
            // P12 additions
            ['slug' => 'payment.configuration',             'description' => 'Manage bank integration configuration: add banks, configure accounts, manage lifecycle and credentials'],
            // P15 additions
            ['slug' => 'payment.refunds.view',              'description' => 'View refund records and timelines'],
            ['slug' => 'payment.refunds.manage',            'description' => 'Query refund status and trigger manual status updates'],
            // P16 additions
            ['slug' => 'payment.disputes.view',             'description' => 'View dispute cases, timelines, and customer summaries'],
            ['slug' => 'payment.disputes.manage',           'description' => 'Authorise holds, submit evidence, and record dispute outcomes'],
            ['slug' => 'payment.disputes.finance_review',   'description' => 'Acknowledge finance review for liability-exception dispute cases'],
            // P17 additions
            ['slug' => 'payment.statement.ingest',             'description' => 'Submit provider settlement statements via the ingestion callback'],
            ['slug' => 'payment.statement.view',               'description' => 'View provider statements, statement lines, and clearing records'],
            ['slug' => 'payment.reconciliation.view_corrections', 'description' => 'View reconciliation correction requests'],
            ['slug' => 'payment.reconciliation.approve_correction', 'description' => 'Approve or reject reconciliation correction requests (dual-approval gate)'],
        ];

        foreach ($permissions as $permission) {
            $permission += ['id' => (string) \Illuminate\Support\Str::uuid7(),
                'display_name' => \Illuminate\Support\Str::headline(str_replace('.', ' ', $permission['slug'])),
                'module' => 'payment', 'is_system_permission' => true,
                'created_at' => now(), 'updated_at' => now()];
            DB::connection('identity')->table('identity.permissions')->upsert(
                $permission,
                ['slug'],
                ['description']
            );
        }

        // Role → permission assignments.
        // Roles may not exist in all environments; skip silently rather than fail.
        $roleAssignments = [
            'customer' => ['payment.intents.read', 'payment.intents.manage', 'payment.disputes.view'],
            // FR-B01 assumption: override when FD01 is available
            'agent' => [
                'payment.intents.read',
                'payment.intents.manage',
                'payment.refunds.request',
            ],
            'admin' => [
                'payment.intents.read',
                'payment.intents.manage',
                'payment.refunds.request',
                'payment.refunds.approve',
                'payment.providers.read',
                'payment.providers.manage',
                'payment.config.approve_high_risk',
                'payment.reconciliation.view',
                'payment.reconciliation.manage',
                'payment.config.draft',
                'payment.payouts.view',
                'payment.payouts.manage',
                'payment.exports.view',
                'payment.configuration',
                // P15 additions
                'payment.refunds.view',
                'payment.refunds.manage',
                // P16 additions
                'payment.disputes.view',
                'payment.disputes.manage',
                'payment.disputes.finance_review',
                // P17 additions
                'payment.statement.ingest',
                'payment.statement.view',
                'payment.reconciliation.view_corrections',
                'payment.reconciliation.approve_correction',
                // payment.callbacks.receive is system-only — NOT assigned to any user role
            ],
            'admin-officer' => [
                'payment.intents.read',
                'payment.refunds.request',
                'payment.providers.read',
                'payment.reconciliation.view',
                'payment.exports.view',
                // P17 additions
                'payment.statement.view',
                'payment.reconciliation.view_corrections',
            ],
            'customer-support' => [
                'payment.intents.read',
            ],
        ];

        foreach ($roleAssignments as $roleSlug => $permSlugs) {
            $roleId = DB::connection('identity')
                ->table('identity.roles')
                ->where('slug', $roleSlug)
                ->whereNull('deleted_at')
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permSlugs as $permSlug) {
                $permId = DB::connection('identity')
                    ->table('identity.permissions')
                    ->where('slug', $permSlug)
                    ->value('id');

                if ($permId === null) {
                    continue;
                }

                DB::connection('identity')->table('identity.role_permissions')->upsert(
                    ['id' => (string) \Illuminate\Support\Str::uuid7(),
                        'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now()],
                    ['role_id', 'permission_id'], ['role_id']
                );
            }
        }
    }
}
