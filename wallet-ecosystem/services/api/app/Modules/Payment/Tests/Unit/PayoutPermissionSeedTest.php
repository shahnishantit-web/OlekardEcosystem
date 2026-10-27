<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the PaymentPermissionRoleSeeder contains the required payout permissions
 * and that the system-only callback permission is not assigned to any role.
 */
final class PayoutPermissionSeedTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $seederData;

    protected function setUp(): void
    {
        $seederPath = __DIR__.'/../../../../../database/seeders/PaymentPermissionRoleSeeder.php';
        $this->assertFileExists($seederPath, 'PaymentPermissionRoleSeeder.php must exist');

        $source = (string) file_get_contents($seederPath);

        // Extract $permissions array entries via regex
        preg_match_all(
            "/\[\s*'slug'\s*=>\s*'([^']+)'/",
            $source,
            $slugMatches,
        );

        // Extract $roleAssignments entries
        preg_match_all(
            "/'([^']+)'\s*=>\s*\[([^\]]+)\]/s",
            $source,
            $roleMatches,
            PREG_SET_ORDER,
        );

        $this->seederData = [
            'slugs'        => $slugMatches[1] ?? [],
            'source'       => $source,
            'roleMatches'  => $roleMatches,
        ];
    }

    public function test_payment_payouts_view_permission_is_seeded(): void
    {
        $this->assertContains(
            'payment.payouts.view',
            $this->seederData['slugs'],
            'payment.payouts.view must be in the permissions catalogue'
        );
    }

    public function test_payment_payouts_manage_permission_is_seeded(): void
    {
        $this->assertContains(
            'payment.payouts.manage',
            $this->seederData['slugs'],
            'payment.payouts.manage must be in the permissions catalogue'
        );
    }

    public function test_callbacks_receive_is_not_in_any_role_assignment(): void
    {
        $source = $this->seederData['source'];

        // Check $roleAssignments for callbacks.receive
        preg_match(
            '/\$roleAssignments\s*=\s*\[(.+?)\];/s',
            $source,
            $roleAssignmentBlock,
        );

        $block = preg_replace('~//[^\r\n]*~', '', $roleAssignmentBlock[1] ?? '');

        $this->assertStringNotContainsString(
            'payment.callbacks.receive',
            $block,
            'payment.callbacks.receive must NOT be assigned to any role (system-only permission)'
        );
    }
}
