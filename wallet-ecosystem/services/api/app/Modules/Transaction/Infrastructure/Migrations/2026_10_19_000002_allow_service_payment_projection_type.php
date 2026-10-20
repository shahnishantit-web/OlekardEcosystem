<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'platform';
    public function up(): void
    {
        DB::connection('platform')->statement('ALTER TABLE transaction.transaction_projections DROP CONSTRAINT tp_type_check');
        DB::connection('platform')->statement("ALTER TABLE transaction.transaction_projections ADD CONSTRAINT tp_type_check CHECK (type IN ('funding','transfer','withdrawal','refund','service_payment'))");
    }
    public function down(): void
    {
        // Keep existing service payment history readable on rollback.
    }
};
