<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('receipt_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_idempotency_key', 200)->unique();
            $table->string('event_type', 100);
            $table->uuid('payment_intent_id');
            $table->uuid('notification_id')->nullable();
            $table->string('status', 30);
            $table->string('template_slug', 200)->nullable();
            $table->string('template_version', 50)->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.receipt_notification_deliveries
             ADD CONSTRAINT rnd_status_check
             CHECK (status IN ('dispatched', 'failed', 'skipped'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX rnd_payment_intent_id_idx
             ON payment.receipt_notification_deliveries (payment_intent_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX rnd_event_type_created_idx
             ON payment.receipt_notification_deliveries (event_type, created_at)'
        );

        // Seed P19 permissions
        $now = now()->toIso8601String();
        $permissions = [
            'payment.history.read',
            'payment.history.read.organisation',
            'payment.history.read.platform',
            'payment.export.read.organisation',
            'payment.export.read.platform',
        ];

        if (Schema::connection('identity')->hasTable('identity.permissions')) {
            foreach ($permissions as $slug) {
                DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                    'id'                   => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'slug'                 => $slug,
                    'display_name'         => \Illuminate\Support\Str::headline(str_replace('.', ' ', $slug)),
                    'module'               => 'payment',
                    'is_system_permission' => true,
                    'description'          => 'P19 Payment permission: ' . $slug,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissions = [
            'payment.history.read',
            'payment.history.read.organisation',
            'payment.history.read.platform',
            'payment.export.read.organisation',
            'payment.export.read.platform',
        ];

        if (Schema::connection('identity')->hasTable('identity.permissions')) {
            DB::connection('identity')->table('identity.permissions')
                ->whereIn('slug', $permissions)
                ->delete();
        }

        Schema::connection('payment')->dropIfExists('receipt_notification_deliveries');
    }
};
