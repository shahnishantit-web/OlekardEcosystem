<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityTestCase;

/**
 * T049 — identity:create-admin command tests covering creation, conflict, and --force idempotency.
 *
 * Governs: FR-E04-026 (create-admin command), FR-E04-026b (--force = idempotent no-op),
 *          BR-E04-013 (unique admin email).
 */
final class CreateAdminCommandTest extends IdentityTestCase
{
    private string $email    = 'test-admin-cmd@t.local';
    private string $name     = 'Test Admin';
    private string $password = 'SecurePass123!';

    public function test_creates_admin_user_and_outputs_success_message(): void
    {
        $this->artisan('identity:create-admin', [
            '--email'    => $this->email,
            '--name'     => $this->name,
            '--password' => $this->password,
        ])
            ->expectsOutputToContain("Admin user [{$this->email}] created successfully.")
            ->assertSuccessful();

        $count = DB::connection('identity')
            ->table('identity.users')
            ->where('email', $this->email)
            ->where('actor_type', 'ADMIN')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_fails_if_email_exists_without_force(): void
    {
        // Pre-create the admin user
        $this->artisan('identity:create-admin', [
            '--email'    => $this->email,
            '--name'     => $this->name,
            '--password' => $this->password,
        ])->assertSuccessful();

        // Second attempt without --force → FAILURE
        $this->artisan('identity:create-admin', [
            '--email'    => $this->email,
            '--name'     => $this->name,
            '--password' => $this->password,
        ])
            ->expectsOutputToContain("Admin with email [{$this->email}] already exists. Use --force to skip.")
            ->assertFailed();
    }

    public function test_force_flag_is_idempotent_noop_and_does_not_modify_existing_account(): void
    {
        // Pre-create the admin user
        $this->artisan('identity:create-admin', [
            '--email'    => $this->email,
            '--name'     => $this->name,
            '--password' => $this->password,
        ])->assertSuccessful();

        $before = DB::connection('identity')
            ->table('identity.users')
            ->where('email', $this->email)
            ->first(['id', 'status', 'updated_at']);

        // --force should succeed but make no changes (FR-E04-026b)
        $this->artisan('identity:create-admin', [
            '--email'    => $this->email,
            '--name'     => $this->name,
            '--password' => 'DifferentPassword999!',
            '--force'    => true,
        ])
            ->expectsOutputToContain('Admin account already exists; no changes made.')
            ->assertSuccessful();

        $after = DB::connection('identity')
            ->table('identity.users')
            ->where('email', $this->email)
            ->first(['id', 'status', 'updated_at']);

        // DB record must be unchanged — password NOT updated, status NOT changed
        $this->assertSame((string) $before->id, (string) $after->id);
        $this->assertSame((string) $before->status, (string) $after->status);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);
    }

    public function test_fails_with_error_when_required_options_are_missing(): void
    {
        // Omitting --name and --password causes the command to prompt interactively.
        // Simulate a non-interactive session by answering both prompts with empty string,
        // which trips the empty() guard and produces FAILURE (FR-E04-026).
        $this->artisan('identity:create-admin', [
            '--email' => $this->email,
        ])
            ->expectsQuestion('Display name', '')
            ->expectsQuestion('Password', '')
            ->expectsOutputToContain('Email, name, and password are required.')
            ->assertFailed();
    }
}
