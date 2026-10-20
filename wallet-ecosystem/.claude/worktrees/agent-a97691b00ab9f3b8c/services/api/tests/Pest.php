<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Apply the Laravel TestCase to all Pest test suites so $this->app is
| available in every beforeEach/test closure.
|
| Module tests are co-located under app/Modules/<Module>/Tests/ and are
| added to phpunit.xml test suites; this file covers those paths.
*/

// No global uses() for Feature/Api/Architecture — those test files each declare their own test case inline.

// Identity module tests: IdentityTestCase adds Redis::fake() on top of
// DatabaseTransactions + PlatformConfigurationSeeder from Support\TestCase.
uses(Tests\Support\IdentityTestCase::class)->in(
    '../app/Modules/Identity/Tests/Feature',
    '../app/Modules/Identity/Tests/Security',
    '../app/Modules/Identity/Tests/Unit',
    '../app/Modules/Identity/Tests/Integration',
);

// Profile module tests: Support\TestCase adds DatabaseTransactions + PlatformConfigurationSeeder.
uses(Tests\Support\TestCase::class)->in(
    '../app/Modules/Profile/Tests/Feature',
    '../app/Modules/Profile/Tests/Security',
    '../app/Modules/Profile/Tests/Unit',
    '../app/Modules/Profile/Tests/Integration',
);
