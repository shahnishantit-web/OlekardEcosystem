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
        Schema::connection('platform')->create('ops.alert_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->jsonb('metric_condition');
            $table->string('severity', 20);
            $table->jsonb('notification_channels')->default('[]');
            $table->string('grouping_key', 100)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.alert_rules');
    }
};
