<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

final class NotificationPermissions
{
    public const TEMPLATES_READ = 'notifications.templates.read';

    public const TEMPLATES_WRITE = 'notifications.templates.write';

    public const LOG_READ = 'notifications.log.read';

    public const RETRY = 'notifications.retry';

    public const RULES_READ = 'notifications.rules.read';

    public const RULES_WRITE = 'notifications.rules.write';

    public const PREFERENCES_MANAGE = 'notifications.preferences.manage';

    public const INBOX_READ = 'notifications.inbox.read';

    public const ADMIN_SEND = 'notifications.admin.send';
}
