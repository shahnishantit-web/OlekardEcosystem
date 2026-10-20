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

    private function valueRow(string $key, string $value, string $valueType, string $description): array
    {
        return [
            'id'              => (string) Str::orderedUuid(),
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

    private function tbdRow(string $key, string $valueType, string $description): array
    {
        return [
            'id'              => (string) Str::orderedUuid(),
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

    public function up(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            $this->valueRow(
                'agent.approval.require_maker_checker',
                'true',
                'boolean',
                'Enforce maker-checker: approver must differ from last admin to update the application (FR-E12-009)'
            ),
            $this->valueRow(
                'agent.approval.auto_approve',
                'false',
                'boolean',
                'When true, kyc_passed transitions directly to approved bypassing under_review queue (FR-E12-010)'
            ),
            $this->valueRow(
                'agent.kyc.provider',
                'stub',
                'string',
                'TBD-E12-002: Agent KYC provider adapter name; stub default until real vendor selected'
            ),
            $this->valueRow(
                'agent.kyc.stub_result',
                'passed',
                'string',
                'Stub KYC provider result: "passed" or "failed" (test/dev only)'
            ),
            $this->valueRow(
                'agent.kyc.admin_bypass',
                'false',
                'boolean',
                'When true, KYC step is bypassed and application moves directly to kyc_passed'
            ),
            $this->valueRow(
                'agent.document.max_size_bytes',
                '10485760',
                'number',
                'Maximum allowed document upload size in bytes (10 MiB default) (FR-E12-007)'
            ),
            $this->valueRow(
                'agent.document.allowed_formats',
                'application/pdf,image/jpeg,image/png',
                'string',
                'Comma-separated allowed MIME types for agent documents (FR-E12-007)'
            ),
            $this->tbdRow(
                'agent.document.virus_scan.provider',
                'string',
                'TBD-E12-003: Virus scanner vendor; null stub until infrastructure decision made (FR-E12-024)'
            ),
            $this->valueRow(
                'agent.customer.search.min_length',
                '3',
                'number',
                'Minimum characters required for agent customer search query (FR-E12-015)'
            ),
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'agent.approval.require_maker_checker',
                'agent.approval.auto_approve',
                'agent.kyc.provider',
                'agent.kyc.stub_result',
                'agent.kyc.admin_bypass',
                'agent.document.max_size_bytes',
                'agent.document.allowed_formats',
                'agent.document.virus_scan.provider',
                'agent.customer.search.min_length',
            ])
            ->where('version', 1)
            ->delete();
    }
};
