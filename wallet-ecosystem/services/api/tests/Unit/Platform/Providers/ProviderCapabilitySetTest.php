<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('::of() deduplicates identical capabilities', function () {
    $set = ProviderCapabilitySet::of(
        ProviderCapabilityEnum::InitiatePayment,
        ProviderCapabilityEnum::InitiatePayment,
        ProviderCapabilityEnum::QueryBalance,
    );
    expect($set->count())->toBe(2);
});

test('has() returns true for a present capability', function () {
    $set = ProviderCapabilitySet::of(ProviderCapabilityEnum::DeliverOtp);
    expect($set->has(ProviderCapabilityEnum::DeliverOtp))->toBeTrue();
});

test('has() returns false for an absent capability', function () {
    $set = ProviderCapabilitySet::of(ProviderCapabilityEnum::DeliverOtp);
    expect($set->has(ProviderCapabilityEnum::VerifyIdentity))->toBeFalse();
});

test('::empty() produces isEmpty()=true and count()=0', function () {
    $set = ProviderCapabilitySet::empty();
    expect($set->isEmpty())->toBeTrue();
    expect($set->count())->toBe(0);
});

test('::of() with all ten capability enum cases', function () {
    $set = ProviderCapabilitySet::of(...ProviderCapabilityEnum::cases());
    expect($set->count())->toBe(10);
    expect($set->isEmpty())->toBeFalse();
});

test('all() returns an array of the expected capabilities', function () {
    $set = ProviderCapabilitySet::of(
        ProviderCapabilityEnum::ScoreRisk,
        ProviderCapabilityEnum::ScreenSanctions,
    );
    expect($set->all())->toHaveCount(2);
    expect($set->has(ProviderCapabilityEnum::ScoreRisk))->toBeTrue();
    expect($set->has(ProviderCapabilityEnum::ScreenSanctions))->toBeTrue();
});
