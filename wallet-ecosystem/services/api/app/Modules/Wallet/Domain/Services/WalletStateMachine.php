<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Services;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InvalidWalletTransitionException;

final class WalletStateMachine
{
    /** Valid transitions: from → [to, ...] */
    private const VALID_TRANSITIONS = [
        'pending'    => ['active', 'closed'],
        'active'     => ['frozen', 'suspended', 'restricted', 'closed'],
        'frozen'     => ['active', 'closed'],
        'suspended'  => ['active', 'closed'],
        'restricted' => ['active', 'closed'],
        'closed'     => [],
    ];

    /**
     * Asserts the transition is valid; throws InvalidWalletTransitionException if not.
     * Idempotent: closed→closed is not a valid transition (terminal state).
     */
    public function transition(WalletStatus $from, WalletStatus $to): void
    {
        $allowed = self::VALID_TRANSITIONS[$from->value] ?? [];

        if (!in_array($to->value, $allowed, true)) {
            throw new InvalidWalletTransitionException($from, $to);
        }
    }

    public function canTransition(WalletStatus $from, WalletStatus $to): bool
    {
        $allowed = self::VALID_TRANSITIONS[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }
}
