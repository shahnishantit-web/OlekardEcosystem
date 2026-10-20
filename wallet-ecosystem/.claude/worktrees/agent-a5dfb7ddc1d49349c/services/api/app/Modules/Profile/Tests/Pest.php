<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Profile Module — Pest Bootstrap
|--------------------------------------------------------------------------
| Applies Tests\Support\TestCase (DatabaseTransactions + seeder) to all
| Profile test sub-directories so $this->app is available in every test
| closure and each test is wrapped in a database transaction.
|
| Run Profile tests in isolation:
|   php artisan test app/Modules/Profile/Tests
*/

uses(Tests\Support\TestCase::class)->in('Feature', 'Security', 'Unit', 'Integration');
