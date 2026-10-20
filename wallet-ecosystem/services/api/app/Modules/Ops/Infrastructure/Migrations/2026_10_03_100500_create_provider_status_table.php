<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('ops.provider_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('provider_type', 50)->unique();
            $table->string('status', 20)->default('healthy');
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('degraded_since')->nullable();
            $table->timestampTz('down_since')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.provider_statuses');
    }
};
