<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'platform';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    private function tbdRow(string $key, string $valueType, string $description): array
    {
        return [
            'id'              => (string) Str::uuid(),
            'config_key'      => $key,
            'config_value'    => null,
            'value_type'      => $valueType,
            'status'          => 'active',
            'version'         => 1,
            'is_tbd'          => true,
            'description'     => $description,
            'effective_from'  => null,
            'effective_until' => null,
            'created_at'      => $this->now(),
            'updated_at'      => $this->now(),
        ];
    }

    private function valueRow(string $key, string $value, string $valueType, string $description): array
    {
        return [
            'id'              => (string) Str::uuid(),
            'config_key'      => $key,
            'config_value'    => $value,
            'value_type'      => $valueType,
            'status'          => 'active',
            'version'         => 1,
            'is_tbd'          => false,
            'description'     => $description,
            'effective_from'  => null,
            'effective_until' => null,
            'created_at'      => $this->now(),
            'updated_at'      => $this->now(),
        ];
    }

    public function up(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            $this->tbdRow(
                'kyc.validity_period_days',
                'number',
                'TBD-E05-002: Days an approved KYC case remains valid before expires_at triggers re-verification'
            ),
            $this->tbdRow(
                'kyc.max_document_size_bytes',
                'number',
                'TBD-E05-002: Maximum allowed document file size in bytes'
            ),
            $this->tbdRow(
                'kyc.accepted_mime_types',
                'string',
                'TBD-E05-002: Comma-separated accepted MIME types for KYC documents (e.g., image/jpeg,image/png,application/pdf)'
            ),
            $this->tbdRow(
                'kyc.provider',
                'string',
                'TBD-E05-001: External KYC verification provider name; null while TBD-E05-001 unresolved'
            ),
            $this->valueRow(
                'kyc.auto_verify_enabled',
                'false',
                'boolean',
                'Safe default: all cases go to manual review queue until TBD-E05-001 is resolved'
            ),
            $this->valueRow(
                'kyc.provider_max_retries',
                '3',
                'number',
                'Maximum retry attempts for automated KYC provider calls before job exhausts retries'
            ),
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'kyc.validity_period_days',
                'kyc.max_document_size_bytes',
                'kyc.accepted_mime_types',
                'kyc.provider',
                'kyc.auto_verify_enabled',
                'kyc.provider_max_retries',
            ])
            ->where('version', 1)
            ->delete();
    }
};
