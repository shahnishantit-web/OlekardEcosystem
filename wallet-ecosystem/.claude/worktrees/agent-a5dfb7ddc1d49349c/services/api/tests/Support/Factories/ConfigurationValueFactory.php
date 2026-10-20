<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ConfigurationValueFactory
{
    private string  $namespace   = 'platform';
    private string  $key         = '';
    private mixed   $value       = null;
    private string  $valueType   = 'string';
    private bool    $isEncrypted = false;
    private bool    $isReadonly  = false;
    private ?string $description = null;

    public function __construct()
    {
        $this->key   = 'test.key.' . uniqid();
        $this->value = 'test-value';
    }

    public static function new(): self
    {
        return new self();
    }

    public function withNamespace(string $namespace): self
    {
        $clone            = clone $this;
        $clone->namespace = $namespace;
        return $clone;
    }

    public function withKey(string $key): self
    {
        $clone      = clone $this;
        $clone->key = $key;
        return $clone;
    }

    public function withValue(mixed $value, string $type = 'string'): self
    {
        $clone            = clone $this;
        $clone->value     = $value;
        $clone->valueType = $type;
        return $clone;
    }

    public function integer(int $value): self
    {
        return $this->withValue($value, 'integer');
    }

    public function boolean(bool $value): self
    {
        return $this->withValue($value, 'boolean');
    }

    public function readonly(): self
    {
        $clone             = clone $this;
        $clone->isReadonly = true;
        return $clone;
    }

    public function create(): object
    {
        $id = (string) Uuid::uuid7();

        $serialized = match ($this->valueType) {
            'boolean' => $this->value ? 'true' : 'false',
            'json'    => json_encode($this->value),
            default   => (string) $this->value,
        };

        DB::connection('platform')->table('platform.configuration_values')->insert([
            'id'           => $id,
            'namespace'    => $this->namespace,
            'key'          => $this->key,
            'value'        => $serialized,
            'value_type'   => $this->valueType,
            'is_encrypted' => $this->isEncrypted,
            'is_readonly'  => $this->isReadonly,
            'description'  => $this->description,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return (object) [
            'id'        => $id,
            'namespace' => $this->namespace,
            'key'       => $this->key,
            'value'     => $this->value,
        ];
    }
}
