<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $db = Schema::connection('community_services');
        if (! $db->hasColumn('community_services.event_inventory_holds', 'order_id')) {
            $db->table('community_services.event_inventory_holds', static function (Blueprint $table): void {
                $table->uuid('order_id')->nullable()->index();
            });
        }
        if (! $db->hasTable('community_services.event_order_quotes')) {
            $db->create('community_services.event_order_quotes', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('purchaser_actor_id')->index();
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->jsonb('hold_ids');
                $table->jsonb('line_snapshot');
                $table->jsonb('pricing_snapshot');
                $table->char('confirmation_hash', 64);
                $table->string('status', 24)->default('quoted');
                $table->timestampTz('expires_at')->index();
                $table->timestampTz('confirmed_at')->nullable();
                $table->timestampsTz();
                $table->unique(['event_id', 'purchaser_actor_id', 'idempotency_key'], 'event_order_quote_idempotency_uq');
            });
        }
        if (! $db->hasTable('community_services.event_orders')) {
            $db->create('community_services.event_orders', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('quote_id')->unique();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('purchaser_actor_id')->index();
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->jsonb('line_snapshot');
                $table->jsonb('pricing_snapshot');
                $table->char('confirmation_hash', 64);
                $table->bigInteger('subtotal_minor');
                $table->bigInteger('fees_minor');
                $table->bigInteger('taxes_minor');
                $table->bigInteger('total_minor');
                $table->char('currency_code', 3);
                $table->unsignedSmallInteger('currency_precision');
                $table->string('seller_merchant_reference', 160);
                $table->string('fee_policy_version', 80);
                $table->string('tax_policy_version', 80);
                $table->string('order_state', 32)->default('awaiting_payment');
                $table->string('payment_state', 32)->default('uninitiated');
                $table->string('accounting_state', 32)->default('unverified');
                $table->string('fulfilment_state', 32)->default('pending');
                $table->uuid('payment_intent_id')->nullable()->unique();
                $table->timestampTz('currency_locked_at');
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
                $table->unique(['event_id', 'purchaser_actor_id', 'idempotency_key'], 'event_order_idempotency_uq');
            });
        }
        if (! $db->hasTable('community_services.event_order_lines')) {
            $db->create('community_services.event_order_lines', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->index();
                $table->uuid('hold_id')->unique();
                $table->uuid('category_id')->index();
                $table->string('category_name', 120);
                $table->unsignedInteger('quantity');
                $table->bigInteger('unit_price_minor');
                $table->bigInteger('line_subtotal_minor');
                $table->timestampTz('created_at');
                $table->foreign('order_id')->references('id')->on('community_services.event_orders')->restrictOnDelete();
                $table->foreign('hold_id')->references('id')->on('community_services.event_inventory_holds')->restrictOnDelete();
            });
        }
        if (! $db->hasTable('community_services.event_tickets')) {
            $db->create('community_services.event_tickets', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->index();
                $table->uuid('order_line_id')->index();
                $table->unsignedInteger('entitlement_number');
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('owner_actor_id')->index();
                $table->uuid('category_id')->index();
                $table->string('category_name', 120);
                $table->char('credential_hash', 64)->unique();
                $table->text('credential_encrypted');
                $table->string('status', 24)->default('valid');
                $table->timestampTz('issued_at');
                $table->timestampsTz();
                $table->unique(['order_line_id', 'entitlement_number'], 'event_ticket_entitlement_uq');
                $table->foreign('order_id')->references('id')->on('community_services.event_orders')->restrictOnDelete();
                $table->foreign('order_line_id')->references('id')->on('community_services.event_order_lines')->restrictOnDelete();
            });
        }
        if (! $db->hasTable('community_services.event_payment_inbox')) {
            $db->create('community_services.event_payment_inbox', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('source_event_id', 128)->nullable()->unique();
                $table->uuid('payment_intent_id')->unique();
                $table->uuid('order_id')->nullable()->index();
                $table->uuid('organisation_id')->index();
                $table->string('payload_hash', 64);
                $table->jsonb('financial_facts');
                $table->jsonb('verified_evidence')->nullable();
                $table->string('state', 32)->default('received');
                $table->string('ack_state', 24)->default('pending');
                $table->text('block_reason')->nullable();
                $table->timestampTz('received_at');
                $table->timestampTz('processed_at')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        $db = Schema::connection('community_services');
        foreach (['event_payment_inbox', 'event_tickets', 'event_order_lines', 'event_orders', 'event_order_quotes'] as $table) {
            $db->dropIfExists('community_services.'.$table);
        }
        if ($db->hasColumn('community_services.event_inventory_holds', 'order_id')) {
            $db->table('community_services.event_inventory_holds', static fn (Blueprint $table) => $table->dropColumn('order_id'));
        }
    }
};
