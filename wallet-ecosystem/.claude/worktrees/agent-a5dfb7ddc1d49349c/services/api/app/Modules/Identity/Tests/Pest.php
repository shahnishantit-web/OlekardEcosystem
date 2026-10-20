<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Identity Module — Pest Bootstrap
|--------------------------------------------------------------------------
| Applies Tests\TestCase (Laravel application boot) to all Identity test
| sub-directories so $this->app is available in every test closure.
|
| Run Identity tests in isolation:
|   php artisan test app/Modules/Identity/Tests
*/

uses(Tests\TestCase::class)->in('Feature', 'Security', 'Unit');
