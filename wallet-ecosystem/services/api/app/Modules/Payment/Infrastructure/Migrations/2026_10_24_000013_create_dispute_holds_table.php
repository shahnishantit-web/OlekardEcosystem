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
        Schema::connection('payment')->create('dispute_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('dispute_case_id')->unique(); // one hold per case
            $table->bigInteger('hold_amount_minor');
            $table->char('currency_code', 3);
            $table->boolean('settled_funds_receivable')->default(false);
            $table->uuid('authorised_by');
            $table->timestampTz('authorised_at')->useCurrent();
            $table->timestampTz('released_at')->nullable();
        });

        DB::connection('payment')->statement(
            'ALTER TABLE payment.dispute_holds
             ADD CONSTRAINT dispute_holds_case_fk
             FOREIGN KEY (dispute_case_id) REFERENCES payment.payment_dispute_cases (id)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('dispute_holds');
    }
};
