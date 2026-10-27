<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payout_beneficiaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id');
            $table->string('account_type', 50);
            $table->text('account_number');
            $table->string('bank_code', 50)->nullable();
            $table->string('bank_name', 200)->nullable();
            $table->string('account_holder_name', 200);
            $table->char('currency_code', 3);
            $table->string('status', 30)->default('pending_verification');
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_reference', 200)->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_beneficiaries
             ADD CONSTRAINT payout_beneficiaries_account_type_check
             CHECK (account_type IN ('bank_account','mobile_money','wallet'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_beneficiaries
             ADD CONSTRAINT payout_beneficiaries_status_check
             CHECK (status IN ('pending_verification','verified','suspended','rejected'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_beneficiaries_organisation_id_idx
             ON payment.payout_beneficiaries (organisation_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_beneficiaries_status_idx
             ON payment.payout_beneficiaries (status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_beneficiaries_account_type_idx
             ON payment.payout_beneficiaries (account_type)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_beneficiaries_created_at_idx
             ON payment.payout_beneficiaries (created_at)'
        );

        // Identity owns the UUID/slug permission catalogue. Preserve the P18
        // permission names without depending on an unrelated Spatie schema.
        foreach (['view', 'initiate', 'approve', 'execute', 'manage_beneficiaries'] as $operation) {
            $slug = 'payment.payout.'.$operation;
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(),
                'slug' => $slug,
                'display_name' => ucwords(str_replace('_', ' ', 'Payout '.$operation)),
                'module' => 'payment',
                'is_system_permission' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_beneficiaries_organisation_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_beneficiaries_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_beneficiaries_account_type_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_beneficiaries_created_at_idx'
        );
        Schema::connection('payment')->dropIfExists('payout_beneficiaries');
    }
};
