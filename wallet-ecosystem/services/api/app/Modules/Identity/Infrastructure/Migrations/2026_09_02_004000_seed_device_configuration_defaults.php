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

    private function row(
        string $key,
        string $value,
        string $valueType,
        string $description,
    ): array {
        return [
            'id'             => (string) Str::uuid(),
            'config_key'     => $key,
            'config_value'   => $value,
            'value_type'     => $valueType,
            'status'         => 'active',
            'version'        => 1,
            'is_tbd'         => false,
            'description'    => $description,
            'effective_from'  => null,
            'effective_until' => null,
            'created_at'     => $this->now(),
            'updated_at'     => $this->now(),
        ];
    }

    public function up(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            $this->row('device.max_devices_per_user',            '10',    'number',  'Maximum number of registered devices per user account'),
            $this->row('device.new_device_notification_enabled', 'true',  'boolean', 'Send notification to user when a new device registers'),
            $this->row('device.new_device_acknowledgment_ttl',   '86400', 'number',  'Seconds the user has to acknowledge a new device (default: 24 h)'),
            $this->row('device.change_detection_enabled',        'true',  'boolean', 'Demote device to pending_verification on fingerprint mismatch'),
            $this->row('device.suspicious_detection_enabled',    'true',  'boolean', 'Enable background suspicious-device detection job'),
            $this->row('device.fingerprint_min_entropy_bits',    '32',    'number',  'Minimum fingerprint entropy bits required for registration'),
            $this->row('device.max_accounts_per_fingerprint',    '3',     'number',  'Maximum distinct accounts allowed per device fingerprint'),
            $this->row('device.max_device_changes_per_window',   '3',     'number',  'Device-change events that trigger suspicious detection per window'),
            $this->row('device.suspicious_change_window_hours',  '24',    'number',  'Rolling window in hours for device-change rate counting'),
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'device.max_devices_per_user',
                'device.new_device_notification_enabled',
                'device.new_device_acknowledgment_ttl',
                'device.change_detection_enabled',
                'device.suspicious_detection_enabled',
                'device.fingerprint_min_entropy_bits',
                'device.max_accounts_per_fingerprint',
                'device.max_device_changes_per_window',
                'device.suspicious_change_window_hours',
            ])
            ->where('version', 1)
            ->delete();
    }
};
