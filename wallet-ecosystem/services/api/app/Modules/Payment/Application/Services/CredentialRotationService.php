<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\ValueObjects\SecretReference;
use App\Modules\Payment\Infrastructure\Models\ApiOperationConfigModel;
use App\Modules\Payment\Infrastructure\Models\ConfigurationVersionModel;
use App\Modules\Payment\Infrastructure\Models\CredentialRotationLogModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T059: Manages credential rotation for integration accounts.
 *
 * FR-014: Raw credential values MUST NOT appear in any log, export, or response.
 * FR-024: In-flight attempts must resolve credentials via time-aware rotation log lookup.
 */
final class CredentialRotationService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /**
     * Rotate a credential reference for the given secret role.
     * Updates active api_operation_configs for this role; writes rotation log entry.
     *
     * @throws \InvalidArgumentException if newKeyName contains a raw-secret pattern
     * @throws \RuntimeException if no operation config uses the given role
     */
    public function rotate(
        string  $accountId,
        string  $secretRole,
        string  $newKeyName,
        string  $actorId,
        ?string $reason = null,
    ): CredentialRotationLogModel {
        // FR-014: Validate key name is not a raw credential
        new SecretReference($newKeyName);

        return DB::connection('payment')->transaction(function () use ($accountId, $secretRole, $newKeyName, $actorId, $reason): CredentialRotationLogModel {
            // Find current key name in active operation configs
            $configs = ApiOperationConfigModel::query()
                ->where('account_id', $accountId)
                ->get()
                ->filter(static fn ($c): bool => isset($c->secret_key_references[$secretRole]));

            if ($configs->isEmpty()) {
                throw new \RuntimeException(
                    "Account {$accountId} has no operation config using secret role '{$secretRole}'"
                );
            }

            $previousKeyName = $configs->first()->secret_key_references[$secretRole];

            // Update all operation configs for this account/role with the new key name
            foreach ($configs as $config) {
                $refs                 = $config->secret_key_references;
                $refs[$secretRole]    = $newKeyName;
                $config->secret_key_references = $refs;
                $config->save();
            }

            // Write rotation log entry (key names only, never values)
            $logEntry = CredentialRotationLogModel::create([
                'id'                    => (string) Str::uuid7(),
                'account_id'            => $accountId,
                'secret_role'           => $secretRole,
                'previous_key_name'     => $previousKeyName,
                'new_key_name'          => $newKeyName,
                'rotated_by_actor_id'   => $actorId,
                'rotation_reason'       => $reason,
                'effective_from'        => now(),
            ]);

            // FR-014: Audit logs only contain key names — never raw values
            $this->audit->record(new AuditEventData(
                action:        'payment.credential.rotated',
                actorType:     'admin',
                resourceType:  'payment_provider_account',
                correlationId: $accountId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $accountId,
                afterState:    [
                    'secret_role'      => $secretRole,
                    'rotation_log_id'  => $logEntry->id,
                    // Never log key names or values in afterState
                ],
            ));

            return $logEntry;
        });
    }

    /**
     * FR-024: Time-aware credential resolution for in-flight payment attempts.
     *
     * If $configVersionId is provided, find the ConfigurationVersion record to obtain its
     * activated_at timestamp. Then query credential_rotation_log ordered by effective_from DESC
     * where effective_from <= activated_at to find the key that was active at attempt initiation.
     *
     * If no rotation log exists for that timeframe, return the key from the original operation
     * config snapshot.
     */
    public function resolveCurrentKeyForRole(
        string  $accountId,
        string  $secretRole,
        ?string $configVersionId,
    ): string {
        if ($configVersionId !== null) {
            $version = ConfigurationVersionModel::query()->find($configVersionId);

            if ($version !== null) {
                // Look up the rotation log for the key active at the time this config version was activated
                $rotationEntry = CredentialRotationLogModel::query()
                    ->where('account_id', $accountId)
                    ->where('secret_role', $secretRole)
                    ->where('effective_from', '<=', $version->activated_at)
                    ->orderBy('effective_from', 'desc')
                    ->first();

                if ($rotationEntry !== null) {
                    return $rotationEntry->new_key_name;
                }

                // No rotation record before this config version — use the snapshot's key
                $snapshot = $version->snapshot;
                $initiate = collect($snapshot)->firstWhere('operation_type', 'initiate');

                if ($initiate !== null && isset($initiate['secret_key_references'][$secretRole])) {
                    return $initiate['secret_key_references'][$secretRole];
                }
            }
        }

        // Fall back to current live operation config
        $config = ApiOperationConfigModel::query()
            ->where('account_id', $accountId)
            ->whereNull('config_version_id')
            ->first();

        if ($config !== null && isset($config->secret_key_references[$secretRole])) {
            return $config->secret_key_references[$secretRole];
        }

        throw new \RuntimeException(
            "Cannot resolve key for role '{$secretRole}' on account {$accountId}"
        );
    }
}
