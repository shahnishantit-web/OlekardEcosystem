<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\ValueObjects;

final class SecurityAuditActions
{
    public const PII_ACCESSED = 'security.pii.accessed';
    public const CONFIG_LOCKOUT_CHANGED = 'security.config.lockout_changed';
    public const CONFIG_RATE_LIMIT_CHANGED = 'security.config.rate_limit_changed';
    public const VULN_FINDING_SUPPRESSED = 'security.vuln_finding.suppressed';
    public const VULN_FINDING_RESOLVED = 'security.vuln_finding.resolved';
    public const SECRETS_BOOT_CHECK_FAILED = 'security.secrets.boot_check_failed';
}
