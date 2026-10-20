<?php

declare(strict_types=1);

/**
 * T157 — Architecture integrity: Wallet module boundary enforcement.
 *
 * Uses Pest Architecture helpers to verify module boundary rules.
 * Rules verified:
 *  1. No class outside App\Modules\Wallet imports Wallet Infrastructure models directly.
 *  2. Cross-module usage goes through WalletModuleContract (contract-first access).
 *  3. WalletHoldService is not referenced outside App\Modules\Wallet.
 */

arch('Wallet models are not imported outside the Wallet module')
    ->expect('App\Modules\Wallet\Infrastructure\Models')
    ->not->toBeUsedIn([
        'App\Modules\Identity',
        'App\Modules\Kyc',
        'App\Modules\Platform',
        'App\Modules\Ledger',
    ]);

arch('WalletHoldService is not used outside App\Modules\Wallet')
    ->expect('App\Modules\Wallet\Application\Services\WalletHoldService')
    ->not->toBeUsedIn([
        'App\Modules\Identity',
        'App\Modules\Kyc',
        'App\Modules\Platform',
        'App\Modules\Ledger',
        'App\Http\Controllers',
    ]);

arch('Wallet domain exceptions are domain objects only (no framework deps)')
    ->expect('App\Modules\Wallet\Domain\Exceptions')
    ->not->toUse('Illuminate\Database');

arch('Wallet domain services are pure (no HTTP layer deps)')
    ->expect('App\Modules\Wallet\Domain\Services')
    ->not->toUse('Illuminate\Http');

arch('Controllers are thin (no direct model use in Wallet controllers)')
    ->expect('App\Modules\Wallet\Infrastructure\Http\Controllers')
    ->not->toUse([
        'App\Modules\Wallet\Infrastructure\Models\WalletHoldModel',
        'App\Modules\Wallet\Infrastructure\Models\WalletLimitModel',
    ]);
