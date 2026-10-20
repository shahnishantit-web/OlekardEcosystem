<?php

declare(strict_types=1);

/**
 * T053 — Architecture integrity: Ledger module boundary enforcement.
 *
 * Verifies that Ledger Infrastructure models are NOT imported directly by
 * any non-Ledger module. Cross-module access must go through LedgerModuleContract.
 */

arch('Ledger models are not imported outside the Ledger module')
    ->expect('App\Modules\Ledger\Infrastructure\Models')
    ->not->toBeUsedIn([
        'App\Modules\Identity',
        'App\Modules\Kyc',
        'App\Modules\Platform',
        'App\Modules\Wallet',
    ]);

arch('Ledger domain exceptions contain no framework HTTP or database dependencies')
    ->expect('App\Modules\Ledger\Domain\Exceptions')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Http']);

arch('Ledger domain events contain no framework HTTP or database dependencies')
    ->expect('App\Modules\Ledger\Domain\Events')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Http']);

arch('LedgerEntryController does not access Eloquent models directly')
    ->expect('App\Modules\Ledger\Infrastructure\Http\Controllers\LedgerEntryController')
    ->not->toUse([
        'App\Modules\Ledger\Infrastructure\Models\JournalModel',
        'App\Modules\Ledger\Infrastructure\Models\JournalLineModel',
        'App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel',
    ]);
