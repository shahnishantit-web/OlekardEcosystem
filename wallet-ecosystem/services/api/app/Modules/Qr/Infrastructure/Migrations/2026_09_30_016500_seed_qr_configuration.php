<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $configs = [
            ['config_key' => 'qr.signing_key',                 'config_value' => '',      'value_type' => 'string'],
            ['config_key' => 'qr.dynamic_expiry_seconds',       'config_value' => '300',   'value_type' => 'integer'],
            ['config_key' => 'qr.reservation_ttl_seconds',      'config_value' => '120',   'value_type' => 'integer'],
            ['config_key' => 'qr.payment.fee_type',             'config_value' => 'flat',  'value_type' => 'string'],
            ['config_key' => 'qr.payment.fee_value',            'config_value' => '0',     'value_type' => 'integer'],
            ['config_key' => 'qr.payment.min_amount',           'config_value' => '1',     'value_type' => 'integer'],
            ['config_key' => 'qr.payment.max_amount',           'config_value' => '1000000', 'value_type' => 'integer'],
        ];

        foreach ($configs as $config) {
            DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'config_key'   => $config['config_key'],
                'config_value' => $config['config_value'],
                'value_type'   => $config['value_type'],
                'status'       => 'active',
                'version'      => 1,
                'is_tbd'       => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'qr.signing_key',
                'qr.dynamic_expiry_seconds',
                'qr.reservation_ttl_seconds',
                'qr.payment.fee_type',
                'qr.payment.fee_value',
                'qr.payment.min_amount',
                'qr.payment.max_amount',
            ])
            ->delete();
    }
};
