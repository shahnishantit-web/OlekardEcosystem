<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException;

describe('EloquentUserRepository — updateContactInfo', function (): void {

    beforeEach(function (): void {
        $this->repo = app(UserRepositoryInterface::class);
    });

    it('updates email only, leaves mobile_number unchanged', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26771000001',
            'email'         => 'old@example.com',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $this->repo->updateContactInfo($user->id, 'new@example.com', null);

        $refreshed = $this->repo->findById($user->id);
        expect($refreshed->email)->toBe('new@example.com');
        expect($refreshed->mobile_number)->toBe('+26771000001');
    })->group('identity', 'repositories', 'contact');

    it('updates mobile_number only, leaves email unchanged', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26771000002',
            'email'         => 'keep@example.com',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $this->repo->updateContactInfo($user->id, null, '+26771000099');

        $refreshed = $this->repo->findById($user->id);
        expect($refreshed->mobile_number)->toBe('+26771000099');
        expect($refreshed->email)->toBe('keep@example.com');
    })->group('identity', 'repositories', 'contact');

    it('updates both email and mobile_number when both are provided', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26771000003',
            'email'         => 'before@example.com',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $this->repo->updateContactInfo($user->id, 'after@example.com', '+26771000088');

        $refreshed = $this->repo->findById($user->id);
        expect($refreshed->email)->toBe('after@example.com');
        expect($refreshed->mobile_number)->toBe('+26771000088');
    })->group('identity', 'repositories', 'contact');

    it('is a no-op when both email and mobile are null', function (): void {
        $user = $this->repo->create([
            'mobile_number' => '+26771000004',
            'email'         => 'stable@example.com',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        // Should not throw and should not change anything
        $this->repo->updateContactInfo($user->id, null, null);

        $refreshed = $this->repo->findById($user->id);
        expect($refreshed->email)->toBe('stable@example.com');
        expect($refreshed->mobile_number)->toBe('+26771000004');
    })->group('identity', 'repositories', 'contact');

    it('throws IdentityContactUpdateException on duplicate email', function (): void {
        $this->repo->create([
            'email'      => 'taken@example.com',
            'status'     => 'active',
            'actor_type' => 'CUSTOMER',
        ]);

        $user2 = $this->repo->create([
            'mobile_number' => '+26771000005',
            'email'         => 'other@example.com',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        expect(fn () => $this->repo->updateContactInfo($user2->id, 'taken@example.com', null))
            ->toThrow(IdentityContactUpdateException::class);
    })->group('identity', 'repositories', 'contact');

    it('throws IdentityContactUpdateException on duplicate mobile_number', function (): void {
        $this->repo->create([
            'mobile_number' => '+26771000006',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $user2 = $this->repo->create([
            'mobile_number' => '+26771000007',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        expect(fn () => $this->repo->updateContactInfo($user2->id, null, '+26771000006'))
            ->toThrow(IdentityContactUpdateException::class);
    })->group('identity', 'repositories', 'contact');

    it('throws IdentityContactUpdateException when user does not exist', function (): void {
        expect(fn () => $this->repo->updateContactInfo('00000000-0000-0000-0000-000000000000', 'x@example.com', null))
            ->toThrow(IdentityContactUpdateException::class);
    })->group('identity', 'repositories', 'contact');

})->group('identity', 'repositories', 'contact');
