<?php

declare(strict_types=1);

arch('module domain layers do not import sibling module domains')
    ->expect('App\Modules')
    ->not->toUse(['App\Modules\Identity\Domain'])
    ->ignoring('App\Modules\Identity');

arch('domain layer does not use Eloquent')
    ->expect('App\Modules\*\Domain\*')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('controllers only import Application and Http namespaces')
    ->expect('App\Http\Controllers')
    ->not->toUse('App\Modules\*\Domain\*');
