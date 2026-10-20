<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

final class SecurityPolicyRegistry
{
    /** @return array<string,array{label:string,type:string,default:mixed}> */
    public static function definitions(): array
    {
        return [
            'auth.password.min_length' => ['label' => 'Password minimum length', 'type' => 'integer', 'default' => 10],
            'auth.password.require_uppercase' => ['label' => 'Require uppercase letter', 'type' => 'boolean', 'default' => true],
            'auth.password.require_number' => ['label' => 'Require number', 'type' => 'boolean', 'default' => true],
            'auth.password.require_special' => ['label' => 'Require special character', 'type' => 'boolean', 'default' => true],
            'auth.password.expiry_days' => ['label' => 'Password expiry (days; unconfigured preserves current behavior)', 'type' => 'nullable_integer', 'default' => null],
            'auth.session.inactivity_ttl_admin' => ['label' => 'Admin idle timeout (seconds)', 'type' => 'integer', 'default' => 900],
            'auth.session.inactivity_ttl_customer' => ['label' => 'Customer idle timeout (seconds)', 'type' => 'integer', 'default' => 1800],
            'auth.session.inactivity_ttl_agent' => ['label' => 'Agent idle timeout (seconds)', 'type' => 'integer', 'default' => 1800],
            'auth.session.max_active' => ['label' => 'Maximum active sessions per user (unconfigured = no new cap)', 'type' => 'nullable_integer', 'default' => null],
            'auth.mfa.admin.required' => ['label' => 'Require admin MFA', 'type' => 'boolean', 'default' => false],
            'auth.mfa.role_requirements' => ['label' => 'MFA by role', 'type' => 'roles', 'default' => []],
            'security.admin.ip_allowlist' => ['label' => 'Admin IP allowlist (empty preserves current access)', 'type' => 'cidrs', 'default' => []],
        ];
    }

    public static function contains(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    public static function unwrap(mixed $value): mixed
    {
        return is_array($value) && array_keys($value) === ['value'] ? $value['value'] : $value;
    }

    public static function validate(string $key, mixed $value): void
    {
        $d = self::definitions()[$key] ?? null;
        if (! $d) {
            throw ValidationException::withMessages(['key' => 'Unsupported security policy.']);
        }$value = self::unwrap($value);
        $valid = match ($d['type']) {
            'integer' => is_int($value) && $value > 0,'nullable_integer' => $value === null || (is_int($value) && $value > 0),'boolean' => is_bool($value),'roles','cidrs' => is_array($value),default => false
        };
        if ($key === 'auth.password.min_length' && (! is_int($value) || $value < 10)) {
            $valid = false;
        }
        if ($d['type'] === 'roles' && is_array($value)) {
            foreach ($value as $role => $mode) {
                if (! is_string($role) || ! preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $role) || ! in_array($mode, ['required', 'optional'], true)) {
                    $valid = false;
                }
            }
        }
        if ($d['type'] === 'cidrs' && is_array($value)) {
            if (! array_is_list($value) || count($value) > 1000) {
                $valid = false;
            }foreach ($value as $ip) {
                if (! is_string($ip) || ! self::validNetwork($ip)) {
                    $valid = false;
                }
            }
        }
        if (! $valid) {
            throw ValidationException::withMessages(['value' => 'Invalid value for '.$d['label'].'.']);
        }
    }

    private static function validNetwork(string $network): bool
    {
        $p = explode('/', $network);
        if (count($p) > 2 || ! filter_var($p[0], FILTER_VALIDATE_IP)) {
            return false;
        }if (count($p) === 1) {
            return true;
        }

        return ctype_digit($p[1]) && (int) $p[1] <= (str_contains($p[0], ':') ? 128 : 32);
    }

    /** @param list<string> $allowlist */
    public static function allowsIp(string $ip, array $allowlist): bool
    {
        return $allowlist === [] || IpUtils::checkIp($ip, $allowlist);
    }
}
