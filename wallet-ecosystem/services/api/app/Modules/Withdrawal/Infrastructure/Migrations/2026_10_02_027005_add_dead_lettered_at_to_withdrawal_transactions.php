<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->table('withdrawal.withdrawal_transactions', function (Blueprint $table): void {
            $table->timestampTz('dead_lettered_at')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('withdrawal.withdrawal_transactions', function (Blueprint $table): void {
            $table->dropColumn('dead_lettered_at');
        });
    }
};
