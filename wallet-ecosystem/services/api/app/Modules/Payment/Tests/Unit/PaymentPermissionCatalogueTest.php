<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use Database\Seeders\PaymentPermissionRoleSeeder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaymentPermissionCatalogueTest extends TestCase
{
    private array $permissions;
    private array $roleAssignments;

    protected function setUp(): void
    {
        // Extract private properties via closure binding for white-box test
        $seeder = new PaymentPermissionRoleSeeder();
        $ref    = new ReflectionClass($seeder);

        // Run a captured version to get the internal arrays
        // We instantiate and invoke the seeder's declared arrays directly via reflection
        // by parsing the run() method's literal arrays — instead use a testable wrapper
        // pattern: the seeder class exposes its catalogue via static helpers we validate here.
        // Since the seeder doesn't expose statics, we parse the file source.
        $source = file_get_contents($ref->getFileName());

        // Extract permission slugs via regex
        preg_match_all("/'slug'\s*=>\s*'(payment\.[^']+)'/", $source, $permMatches);
        $this->permissions = $permMatches[1];

        // Extract role assignment arrays
        preg_match_all("/'(agent|admin|admin-officer|customer-support|customer)'\s*=>\s*\[([^\]]+)\]/s", $source, $roleMatches);
        $this->roleAssignments = [];
        foreach ($roleMatches[1] as $i => $role) {
            preg_match_all("/'(payment\.[^']+)'/", $roleMatches[2][$i], $pm);
            $this->roleAssignments[$role] = $pm[1];
        }
    }

    public function test_permission_catalogue_contains_exactly_15_payment_permissions(): void
    {
        $this->assertCount(
            15,
            array_unique($this->permissions),
            'Expected exactly 15 payment.* permissions in the seeder'
        );
    }

    public function test_all_required_permissions_are_present(): void
    {
        $required = [
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
            'payment.callbacks.receive',
            'payment.exports.view',
            'payment.configuration',
        ];

        foreach ($required as $slug) {
            $this->assertContains(
                $slug,
                $this->permissions,
                "Permission '{$slug}' is missing from the seeder catalogue"
            );
        }
    }

    public function test_callbacks_receive_is_not_assigned_to_any_user_role(): void
    {
        foreach ($this->roleAssignments as $role => $perms) {
            $this->assertNotContains(
                'payment.callbacks.receive',
                $perms,
                "payment.callbacks.receive must NOT be assigned to role '{$role}' (system-only permission)"
            );
        }
    }

    public function test_agent_role_assignment_is_present(): void
    {
        $this->assertArrayHasKey('agent', $this->roleAssignments, 'Agent role assignment missing from seeder');
    }

    public function test_agent_role_has_correct_permissions(): void
    {
        $agentPerms = $this->roleAssignments['agent'] ?? [];

        $this->assertContains('payment.intents.read', $agentPerms);
        $this->assertContains('payment.intents.manage', $agentPerms);
        $this->assertContains('payment.refunds.request', $agentPerms);
    }

    public function test_agent_role_cannot_approve_refunds(): void
    {
        $agentPerms = $this->roleAssignments['agent'] ?? [];

        $this->assertNotContains(
            'payment.refunds.approve',
            $agentPerms,
            'Agent role must not have payment.refunds.approve'
        );
    }
}
