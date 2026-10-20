<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderHealthStatus;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('::up() sets status UP and correct lastCheckedAt', function () {
    $at = new \DateTimeImmutable('2026-08-26 12:00:00', new \DateTimeZone('UTC'));
    $h = ProviderHealthStatus::up($at);
    expect($h->status)->toBe(ProviderStatus::UP);
    expect($h->lastCheckedAt)->toBe($at);
    expect($h->reason)->toBeNull();
});

test('::degraded() sets status DEGRADED with reason', function () {
    $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $h = ProviderHealthStatus::degraded('Slow responses', $at);
    expect($h->status)->toBe(ProviderStatus::DEGRADED);
    expect($h->reason)->toBe('Slow responses');
});

test('::down() sets status DOWN with reason', function () {
    $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $h = ProviderHealthStatus::down('Connection refused', $at);
    expect($h->status)->toBe(ProviderStatus::DOWN);
    expect($h->reason)->toBe('Connection refused');
});

test('::unknown() sets status UNKNOWN with non-null lastCheckedAt', function () {
    $h = ProviderHealthStatus::unknown();
    expect($h->status)->toBe(ProviderStatus::UNKNOWN);
    expect($h->lastCheckedAt)->not->toBeNull();
});
