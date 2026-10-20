<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Permissions;

final class PlatformPermissions
{
    public const string CONFIGURATION_UPDATE = 'configuration.policy.update';

    /** @return array<string> */
    public static function all(): array
    {
        return [
            self::CONFIGURATION_UPDATE,
        ];
    }
}
