<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;


describe('EloquentUserRepository', function (): void {

    beforeEach(function (): void {
        $this->repo = app(UserRepositoryInterface::class);
    });

    it('creates and finds a user by mobile number', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26771234567',
            'status'        => 'pending_verification',
            'actor_type'    => 'CUSTOMER',
        ]);

        $found = $this->repo->findByMobile('+26771234567');

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($user->id);
    });

    it('creates and finds a user by email', function (): void {
        $user = $this->repo->create([
            'email'      => 'test@example.com',
            'status'     => 'pending_verification',
            'actor_type' => 'CUSTOMER',
        ]);

        $found = $this->repo->findByEmail('test@example.com');

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($user->id);
    });

    it('enforces unique mobile number constraint', function (): void {
        $this->repo->create([
            'mobile_number' => '+26771234567',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        expect(fn () => $this->repo->create([
            'mobile_number' => '+26771234567',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    })->group('identity', 'repositories');

    it('increments and resets failed_attempt_count', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26771111111',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $this->repo->incrementFailedAttempts($user->id);
        $this->repo->incrementFailedAttempts($user->id);

        $updated = $this->repo->findById($user->id);
        expect($updated->failed_attempt_count)->toBe(2);

        $this->repo->resetFailedAttempts($user->id);

        $reset = $this->repo->findById($user->id);
        expect($reset->failed_attempt_count)->toBe(0);
    });

    it('uses the identity database connection', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26772222222',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $model = \App\Modules\Identity\Infrastructure\Models\User::find($user->id);

        expect($model->getConnectionName())->toBe('identity');
    });

})->group('identity', 'repositories');
