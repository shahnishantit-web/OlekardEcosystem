<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Domain\Events\ProfileCreatedEvent;
use App\Modules\Profile\Infrastructure\Listeners\CreateProfileOnUserRegisteredListener;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

describe('CreateProfileOnUserRegisteredListener', function (): void {

    beforeEach(function (): void {
        $this->userId        = (string) Uuid::uuid7();
        $this->correlationId = (string) Uuid::uuid7();

        $this->contactInfo = new UserContactInfo(
            userId:       $this->userId,
            email:        'user@example.com',
            mobileNumber: '+26771234567',
            actorType:    'CUSTOMER',
        );

        $this->identity = Mockery::mock(IdentityModuleContract::class);
        $this->events   = Mockery::mock(EventPublisherInterface::class);
        $this->audit    = Mockery::mock(AuditServiceInterface::class);
        $this->profiles = app(EloquentUserProfileRepository::class);

        $this->listener = new CreateProfileOnUserRegisteredListener(
            profiles: $this->profiles,
            identity: $this->identity,
            events:   $this->events,
            audit:    $this->audit,
        );
    });

    it('creates a profile and emits ProfileCreatedEvent on first Identity.User.Registered', function (): void {
        $this->identity->shouldReceive('getUserContactInfo')
            ->once()->with($this->userId)->andReturn($this->contactInfo);

        $this->events->shouldReceive('publish')
            ->once()->withArgs(fn ($event) => $event instanceof ProfileCreatedEvent && $event->aggregateId() === $this->userId);

        // T183: audit record must use zero-UUID SYSTEM actor (plan §Audit Plan)
        $this->audit->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data): bool {
                return $data->action === 'profile.user.profile-created'
                    && $data->actorType === 'SYSTEM'
                    && $data->actorId === '00000000-0000-0000-0000-000000000000'
                    && $data->outcome === 'success';
            });

        DB::connection('platform')->beginTransaction();
        $this->listener->handle('Identity.User.Registered', [
            'user_id'        => $this->userId,
            'correlation_id' => $this->correlationId,
            'actor_type'     => 'CUSTOMER',
        ]);
        DB::connection('platform')->commit();

        expect($this->profiles->exists($this->userId))->toBeTrue();
        $profile = $this->profiles->findByUserId($this->userId);
        expect($profile->email)->toBe('user@example.com');
        expect($profile->mobile_number)->toBe('+26771234567');
        expect($profile->account_status)->toBe('active');
    });

    it('is idempotent — second Identity.User.Registered event does NOT create a duplicate profile', function (): void {
        $this->identity->shouldReceive('getUserContactInfo')
            ->once()->andReturn($this->contactInfo);

        $this->events->shouldReceive('publish')->once();
        $this->audit->shouldReceive('record')->once();

        $payload = [
            'user_id'        => $this->userId,
            'correlation_id' => $this->correlationId,
            'actor_type'     => 'CUSTOMER',
        ];

        DB::connection('platform')->beginTransaction();
        $this->listener->handle('Identity.User.Registered', $payload);
        DB::connection('platform')->commit();

        // Second call — profile already exists; identity, events, audit NOT called again
        $this->identity->shouldNotReceive('getUserContactInfo');
        $this->events->shouldNotReceive('publish');
        $this->audit->shouldNotReceive('record');

        $this->listener->handle('Identity.User.Registered', $payload);

        // Still only one profile
        $count = \Illuminate\Support\Facades\DB::connection('profile')
            ->table('profile.user_profiles')->where('user_id', $this->userId)->count();
        expect($count)->toBe(1);
    });

    it('does not commit anything when getUserContactInfo throws BadMethodCallException', function (): void {
        $this->identity->shouldReceive('getUserContactInfo')
            ->once()->andThrow(new \BadMethodCallException('Not implemented'));

        $this->events->shouldNotReceive('publish');
        $this->audit->shouldNotReceive('record');

        expect(fn () => $this->listener->handle('Identity.User.Registered', [
            'user_id'        => $this->userId,
            'correlation_id' => $this->correlationId,
        ]))->toThrow(\BadMethodCallException::class);

        expect($this->profiles->exists($this->userId))->toBeFalse();
    });
});
