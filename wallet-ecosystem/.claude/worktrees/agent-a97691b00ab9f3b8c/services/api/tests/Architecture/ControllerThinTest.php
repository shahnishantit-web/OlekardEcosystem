<?php

declare(strict_types=1);

arch('controllers do not call DB facade directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('controllers do not import domain namespaces')
    ->expect('App\Http\Controllers')
    ->not->toUse('App\Modules\*\Domain\*');
