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
    ->not->toUse('Illuminate\Http')
    // UploadProfilePhotoCommand carries an UploadedFile DTO — tracked as E02 clean-up item.
    ->ignoring('App\Modules\Profile\Application\Commands\UploadProfilePhotoCommand');

arch('Eloquent models are in Infrastructure Persistence only')
    ->expect('App\Modules\*\Domain\*')
    ->not->toExtend('Illuminate\Database\Eloquent\Model');
