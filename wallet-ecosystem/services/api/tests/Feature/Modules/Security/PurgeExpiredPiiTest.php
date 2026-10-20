<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * T077 — Verifies app:purge-expired-pii command behavior.
 * FR-014: PII retention MUST be enforced; expired records must be anonymized/deleted.
 */
final class PurgeExpiredPiiTest extends TestCase
{
    use RefreshDatabase;

    private function seedClosedUser(string $updatedAt): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'           => $id,
            'email'        => "closed-{$id}@example.com",
            'display_name' => 'Old User',
            'phone_number' => '+1000000' . random_int(1000, 9999),
            'status'       => 'closed',
            'created_at'   => $updatedAt,
            'updated_at'   => $updatedAt,
        ]);
        return $id;
    }

    /** @test */
    public function command_is_noop_when_retention_days_is_zero(): void
    {
        config([
            'pii.retention_days.identity'    => 0,
            'pii.retention_days.kyc'         => 0,
            'pii.retention_days.transaction' => 0,
        ]);

        $userId = $this->seedClosedUser(now()->subDays(1000)->toDateTimeString());

        $this->artisan('app:purge-expired-pii')
            ->assertExitCode(0);

        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->first();

        $this->assertNotNull($user, 'Record should not be deleted when retention_days=0');
        $this->assertStringNotContainsString('purged', $user->email ?? '', 'Record should not be anonymized when retention_days=0');
    }

    /** @test */
    public function expired_closed_identity_records_are_anonymized(): void
    {
        $retentionDays = 365;
        config([
            'pii.retention_days.identity'    => $retentionDays,
            'pii.retention_days.kyc'         => 0,
            'pii.retention_days.transaction' => 0,
        ]);

        $oldDate    = now()->subDays($retentionDays + 10)->toDateTimeString();
        $expiredId  = $this->seedClosedUser($oldDate);

        $recentDate   = now()->subDays($retentionDays - 1)->toDateTimeString();
        $activeId     = $this->seedClosedUser($recentDate);

        $this->artisan('app:purge-expired-pii')
            ->assertExitCode(0);

        $expired = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $expiredId)
            ->first();

        $this->assertNotNull($expired);
        $this->assertStringContainsString('purged', $expired->email ?? '', 'Expired record email must be anonymized');
        $this->assertNull($expired->phone_number, 'Expired record phone_number must be nulled');

        $recent = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $activeId)
            ->first();

        $this->assertNotNull($recent);
        $this->assertStringNotContainsString('purged', $recent->email ?? '', 'Recent record must not be anonymized');
    }

    /** @test */
    public function records_within_retention_period_are_untouched(): void
    {
        config([
            'pii.retention_days.identity'    => 365,
            'pii.retention_days.kyc'         => 0,
            'pii.retention_days.transaction' => 0,
        ]);

        $recentDate = now()->subDays(100)->toDateTimeString();
        $userId     = $this->seedClosedUser($recentDate);
        $originalEmail = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->value('email');

        $this->artisan('app:purge-expired-pii')
            ->assertExitCode(0);

        $after = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->value('email');

        $this->assertEquals($originalEmail, $after, 'Records within retention window must be untouched');
    }
}
