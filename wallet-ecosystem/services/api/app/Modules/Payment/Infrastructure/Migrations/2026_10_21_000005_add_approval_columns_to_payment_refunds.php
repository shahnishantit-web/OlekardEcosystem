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
        Schema::connection('payment')->table('payment_refunds', function (Blueprint $table): void {
            $table->boolean('approval_required')->default(false)->after('status');
            $table->uuid('approved_by_actor_id')->nullable()->after('approved_by');
            $table->timestampTz('approved_at')->nullable()->after('approved_by_actor_id');
            $table->uuid('rejected_by_actor_id')->nullable()->after('approved_at');
            $table->timestampTz('rejected_at')->nullable()->after('rejected_by_actor_id');
            $table->string('rejection_reason', 1000)->nullable()->after('rejected_at');
        });

        // Self-approval guard at database level
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_refunds
             ADD CONSTRAINT chk_refund_no_self_approval
             CHECK (approved_by_actor_id IS NULL OR approved_by_actor_id::text != requested_by::text)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_refunds
             DROP CONSTRAINT IF EXISTS chk_refund_no_self_approval'
        );

        Schema::connection('payment')->table('payment_refunds', function (Blueprint $table): void {
            $table->dropColumn([
                'approval_required',
                'approved_by_actor_id',
                'approved_at',
                'rejected_by_actor_id',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
