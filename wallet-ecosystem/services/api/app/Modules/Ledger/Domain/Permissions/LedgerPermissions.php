<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Permissions;

final class LedgerPermissions
{
    public const POST             = 'ledger.post';
    public const READ             = 'ledger.read';
    public const READ_ANY         = 'ledger.read.any';
    public const ACCOUNT_REGISTER = 'ledger.account.register';
    public const ENTRY_REVERSE    = 'ledger.entry.reverse';
}
