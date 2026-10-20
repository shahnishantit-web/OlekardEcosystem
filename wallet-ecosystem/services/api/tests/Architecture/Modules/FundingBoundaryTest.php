<?php

declare(strict_types=1);

use function Pest\ArchitecturePresets\php;

arch('Funding module does not import Wallet internals')
    ->expect('App\Modules\Funding')
    ->not->toUse('App\Modules\Wallet\Infrastructure')
    ->not->toUse('App\Modules\Wallet\Application');

arch('Funding module only accesses Ledger via contract')
    ->expect('App\Modules\Funding')
    ->not->toUse('App\Modules\Ledger\Infrastructure\Models')
    ->not->toUse('App\Modules\Ledger\Application');

arch('Funding controllers are thin — no direct model use')
    ->expect('App\Modules\Funding\Infrastructure\Http\Controllers')
    ->not->toUse('App\Modules\Funding\Infrastructure\Models');

arch('Funding domain layer has no framework dependencies')
    ->expect('App\Modules\Funding\Domain')
    ->not->toUse('Illuminate\Support\Facades');

arch('Funding exceptions extend RuntimeException')
    ->expect('App\Modules\Funding\Domain\Exceptions')
    ->toExtend('RuntimeException');
