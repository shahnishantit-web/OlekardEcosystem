<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Permissions;

final class PlatformPermissions
{
    public const string CONFIGURATION_UPDATE  = 'configuration.policy.update';
    public const string CONFIGURATION_READ    = 'configuration.policy.view';
    public const string CONFIGURATION_WRITE   = 'configuration.policy.update';
    public const string CONFIGURATION_APPROVE = 'configuration.approve';

    /** @return array<string> */
    public static function all(): array
    {
        return array_values(array_unique([
            self::CONFIGURATION_UPDATE,
            self::CONFIGURATION_READ,
            self::CONFIGURATION_WRITE,
            self::CONFIGURATION_APPROVE,
        ]));
    }
}
