<?php

declare(strict_types=1);

arch('controllers do not call DB facade directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('controllers do not import domain namespaces')
    ->expect('App\Http\Controllers')
    ->not->toUse('App\Modules\*\Domain\*');

// E03: Identity Infrastructure controllers must also stay thin
arch('DeviceManagementController does not use DB facade directly')
    ->expect('App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('AdminDeviceController does not use DB facade directly')
    ->expect('App\Modules\Identity\Infrastructure\Http\Controllers\AdminDeviceController')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('Identity Http controllers do not call repositories directly')
    ->expect('App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController')
    ->not->toUse([
        'App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceRepository',
        'App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceTrustHistoryRepository',
        'App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceSecurityEventRepository',
        'App\Modules\Identity\Infrastructure\Repositories\EloquentUserRepository',
        'App\Modules\Identity\Infrastructure\Repositories\EloquentSessionRepository',
    ]);
