<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Credential;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry as Registry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RuntimeSecurityPolicy
{
    public function __construct(private readonly ConfigurationServiceInterface $config, private readonly RoleRepositoryInterface $roles) {}

    public function value(string $key): mixed
    {
        $d = Registry::definitions()[$key];
        $v = Registry::unwrap($this->config->getOrDefault($key, $d['default']));
        if ($v !== null && in_array($d['type'], ['integer', 'nullable_integer'], true) && is_numeric($v)) {
            $v = (int) $v;
        }
        if ($d['type'] === 'boolean' && ! is_bool($v)) {
            $v = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        Registry::validate($key, $v);

        return $v;
    }

    public function requiresMfa(string $user, string $actor): bool
    {
        if (strtoupper($actor) === 'ADMIN' && $this->value('auth.mfa.admin.required')) {
            return true;
        }
        $rules = $this->value('auth.mfa.role_requirements');
        if ($rules === []) {
            return false;
        }
        foreach ($this->roles->getActorRoleSlugs($user) as $role) {
            if (($rules[$role] ?? 'optional') === 'required') {
                return true;
            }
        }

        return false;
    }

    public function assertPasswordCurrent(Credential $credential): void
    {
        $days = $this->value('auth.password.expiry_days');
        $changed = $credential->getAttribute('updated_at') ?? $credential->getAttribute('created_at');
        if (($credential->expires_at !== null && $credential->expires_at->lte(now())) || ($days !== null && ($changed === null || Carbon::parse($changed)->addDays($days)->lte(now())))) {
            $this->deny('PASSWORD_EXPIRED', 'Reset your password before signing in.');
        }
    }

    /** @param array<string,mixed> $attributes */
    public function createSession(SessionRepositoryInterface $sessions, array $attributes): Session
    {
        $max = $this->value('auth.session.max_active');
        if ($max === null) {
            return $sessions->create($attributes);
        }

        return DB::connection('identity')->transaction(function () use ($max, $sessions, $attributes) {
            DB::connection('identity')->table('identity.users')->where('id', $attributes['user_id'])->lockForUpdate()->first();
            $count = 0;
            foreach ($sessions->listActiveForUser($attributes['user_id']) as $s) {
                $actor = strtolower((string) $s->getAttribute('actor_type'));
                $idle = $this->value('auth.session.inactivity_ttl_'.(in_array($actor, ['admin', 'customer', 'agent'], true) ? $actor : 'customer'));
                if (($s->expires_at === null || $s->expires_at->gt(now())) && ($s->last_activity_at === null || $s->last_activity_at->gt(now()->subSeconds($idle)))) {
                    $count++;
                }
            }
            if ($count >= $max) {
                $this->deny('MAX_SESSIONS', 'Sign out another active session before signing in.', 409);
            }

            return $sessions->create($attributes);
        });
    }

    public function authorize(Request $request, Session $session): void
    {
        $actor = strtoupper((string) $request->attributes->get('identity.actor_type'));
        $user = (string) $request->attributes->get('identity.user_id');
        $path = (string) preg_replace('#^api/#', '', $request->path());
        if (($actor === 'ADMIN' || str_starts_with((string) $path, 'v1/admin/')) && ! Registry::allowsIp((string) $request->ip(), $this->value('security.admin.ip_allowlist'))) {
            $this->deny('ADMIN_IP_DENIED', 'Administration access is not allowed from this address.');
        }
        $recovery = in_array($path, ['v1/auth/logout', 'v1/auth/sessions', 'v1/admin/auth/change-password', 'v1/auth/change-password'], true) || preg_match('#^v1/auth/sessions/[0-9a-f-]+$#D', $path);
        $enrollment = in_array($path, ['v1/auth/mfa/methods', 'v1/auth/mfa/enroll/totp', 'v1/auth/mfa/enroll/totp/confirm', 'v1/auth/mfa/enroll/fido2', 'v1/auth/mfa/enroll/fido2/confirm'], true);
        if (! $recovery && ! $enrollment && $this->requiresMfa($user, $actor) && $session->getAttribute('mfa_verified_at') === null) {
            $this->deny('MFA_REQUIRED', 'Complete MFA enrollment and sign in with MFA before continuing.');
        }
        if ($path === 'v1/auth/mfa/disable' && $this->requiresMfa($user, $actor)) {
            $this->deny('MFA_REQUIRED', 'MFA cannot be disabled while required by policy.');
        }
        if ($actor === 'ADMIN' && ! $recovery && ! $enrollment && $this->value('auth.password.expiry_days') !== null) {
            $c = Credential::where('user_id', $user)->where('credential_type', 'password')->where('status', 'active')->first();
            if ($c) {
                $this->assertPasswordCurrent($c);
            }
        }
    }

    /** @return list<string> */
    public function passwordErrors(string $password, bool $creation = false): array
    {
        if ($creation) {
            $configured = false;
            foreach (array_keys(Registry::definitions()) as $key) {
                if (str_starts_with($key, 'auth.password.') && $this->config->isConfigured($key)) {
                    $configured = true;
                }
            }if (! $configured) {
                return mb_strlen($password) < 8 ? ['Password must contain at least 8 characters.'] : [];
            }
        }
        $errors = [];
        $min = $this->value('auth.password.min_length');
        if (mb_strlen($password) < $min) {
            $errors[] = 'Password must contain at least '.$min.' characters.';
        }
        foreach (['uppercase' => '/[A-Z]/', 'number' => '/[0-9]/', 'special' => '/[^A-Za-z0-9]/'] as $name => $pattern) {
            if ($this->value('auth.password.require_'.$name) && ! preg_match($pattern, $password)) {
                $errors[] = 'Password must contain a '.$name.' character.';
            }
        }

        return $errors;
    }

    private function deny(string $code, string $message, int $status = 403): never
    {
        throw new HttpResponseException(response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status));
    }
}
