<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Exceptions\ResourceNotFoundException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ConfigurationApplicationService
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly AuditServiceInterface $auditService,
    ) {}

    public function getValue(string $key): mixed
    {
        return $this->configurationService->get($key);
    }

    public function createValue(
        string $namespace,
        string $key,
        mixed $value,
        string $valueType,
        ?string $description,
        ?string $actorId,
        string $correlationId,
    ): array {
        if (SecurityPolicyRegistry::contains($namespace.'.'.$key) || SecurityPolicyRegistry::contains($key)) {
            throw ValidationException::withMessages(['key' => 'Security policies require maker-checker approval.']);
        }
        $serialized = match ($valueType) {
            'boolean' => $value ? 'true' : 'false',
            'json' => json_encode($value),
            default => (string) $value,
        };

        $id = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.configuration_values')->insert([
            'id' => $id,
            'namespace' => $namespace,
            'key' => $key,
            'value' => $serialized,
            'value_type' => $valueType,
            'is_encrypted' => false,
            'is_readonly' => false,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->auditService->record(new AuditEventData(
            action: 'configuration.value.created',
            actorType: 'USER',
            resourceType: 'ConfigurationValue',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorId,
            resourceId: $id,
            afterState: ['namespace' => $namespace, 'key' => $key, 'value_type' => $valueType],
        ));

        $this->configurationService->invalidate("{$namespace}.{$key}");

        return [
            'id' => $id,
            'namespace' => $namespace,
            'key' => $key,
            'value_type' => $valueType,
        ];
    }

    public function activateValue(
        string $id,
        ?string $actorId,
        string $correlationId,
    ): void {
        $affected = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('id', $id)
            ->update(['updated_at' => now()]);

        if ($affected === 0) {
            throw new ResourceNotFoundException('ConfigurationValue', $id);
        }

        $this->auditService->record(new AuditEventData(
            action: 'configuration.value.activated',
            actorType: 'USER',
            resourceType: 'ConfigurationValue',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorId,
            resourceId: $id,
        ));
    }
}
