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
        Schema::connection('payment')->create('payment.payment_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 32)->unique()->notNullable();
            $table->string('purpose_type', 128)->notNullable();
            $table->uuid('purpose_reference')->notNullable();
            $table->uuid('organisation_id')->notNullable();
            $table->uuid('payer_actor_id')->notNullable();
            $table->string('currency_code', 3)->notNullable();
            $table->bigInteger('amount_minor')->notNullable();
            $table->string('status', 32)->notNullable();
            $table->string('idempotency_key', 255)->notNullable();
            $table->uuid('correlation_id')->notNullable();
            $table->jsonb('metadata')->default('{}')->notNullable();
            $table->timestampTz('expires_at')->notNullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_intents ADD CONSTRAINT payment_intents_amount_minor_check
             CHECK (amount_minor > 0)"
        );
        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_intents ADD CONSTRAINT payment_intents_status_check
             CHECK (status IN (
               'pending','processing','awaiting_action','requires_confirmation',
               'succeeded','failed','cancelled','expired',
               'refunding','partially_refunded','refunded'
             ))"
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_intents_idempotency_payer_idx
             ON payment.payment_intents (idempotency_key, payer_actor_id)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_intents_org_status_idx
             ON payment.payment_intents (organisation_id, status)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_intents_payer_status_idx
             ON payment.payment_intents (payer_actor_id, status)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_intents_purpose_idx
             ON payment.payment_intents (purpose_type, purpose_reference)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_intents_expires_at_idx
             ON payment.payment_intents (expires_at)'
        );

        // Seed: 9 payment.* permissions
        $now = now()->toIso8601String();
        $permissions = [
            'payment.intents.read',
            'payment.intents.manage',
            'payment.refunds.request',
            'payment.refunds.approve',
            'payment.providers.read',
            'payment.providers.manage',
            'payment.config.approve_high_risk',
            'payment.reconciliation.view',
            'payment.reconciliation.manage',
        ];

        // Guard: only seed if the identity.permissions table is accessible
        if (Schema::connection('identity')->hasTable('identity.permissions')) {
            foreach ($permissions as $slug) {
                DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                    'id'          => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'slug'        => $slug,
                    'display_name' => \Illuminate\Support\Str::headline(str_replace('.', ' ', $slug)),
                    'module' => 'payment',
                    'is_system_permission' => true,
                    'description' => 'E48 Payment permission: '.$slug,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_intents');
    }
};
