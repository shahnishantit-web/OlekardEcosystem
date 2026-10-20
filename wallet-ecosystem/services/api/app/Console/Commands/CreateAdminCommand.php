<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\Services\AdminUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateAdminCommand extends Command
{
    protected $signature = 'identity:create-admin
        {--email= : Admin email address}
        {--name= : Admin display name}
        {--password= : Admin password (prompted if not provided)}
        {--force : Overwrite if admin with this email already exists}';

    protected $description = 'Create a new admin user account.';

    public function __construct(
        private readonly AdminUserService $adminUserService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email    = $this->option('email') ?? $this->ask('Email address');
        $name     = $this->option('name')  ?? $this->ask('Display name');
        $password = $this->option('password') ?? $this->secret('Password');

        if (empty($email) || empty($name) || empty($password)) {
            $this->error('Email, name, and password are required.');
            return self::FAILURE;
        }

        $correlationId = (string) Str::uuid();

        try {
            $this->adminUserService->createAdmin(
                ['email' => $email, 'display_name' => $name, 'password' => $password],
                '00000000-0000-0000-0000-000000000000',
                $correlationId,
            );

            $this->info("Admin user [{$email}] created successfully.");
            return self::SUCCESS;
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'ADMIN_USER_EXISTS') {
                if (!$this->option('force')) {
                    $this->error("Admin with email [{$email}] already exists. Use --force to skip.");
                    return self::FAILURE;
                }

                // Force mode: idempotent no-op per FR-E04-026b — existing account is NOT modified.
                $this->info("Admin account already exists; no changes made.");
                return self::SUCCESS;
            }

            $this->error('Failed to create admin: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
