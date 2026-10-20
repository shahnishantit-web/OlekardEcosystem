<?php

declare(strict_types=1);

arch('domain classes do not use Eloquent')
    ->expect('App\Modules\*\Domain\*')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('application classes do not use Eloquent directly')
    ->expect('App\Modules\*\Application\*')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('application classes do not use Http layer')
    ->expect('App\Modules\*\Application\*')
    ->not->toUse('Illuminate\Http');

arch('Eloquent models are in Infrastructure Persistence only')
    ->expect('App\Modules\*\Domain\*')
    ->not->toExtend('Illuminate\Database\Eloquent\Model');
