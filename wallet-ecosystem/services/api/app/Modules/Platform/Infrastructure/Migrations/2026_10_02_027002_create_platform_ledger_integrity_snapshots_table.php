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
        Schema::connection('platform')->create('platform.ledger_integrity_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('scan_at')->notNull();
            $table->integer('wallets_checked')->notNull();
            $table->integer('discrepancy_count')->notNull()->default(0);
            $table->jsonb('affected_wallet_ids')->notNull()->default('[]');
            $table->string('outcome', 20)->notNull();
            $table->integer('duration_ms')->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestampTz('created_at')->notNull()->useCurrent();

            $table->index(['scan_at'], 'lis_scan_at_idx');
            $table->index(['outcome'], 'lis_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.ledger_integrity_snapshots');
    }
};
