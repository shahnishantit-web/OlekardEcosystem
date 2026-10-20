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
        Schema::connection('platform')->create('ops.deployment_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('version', 100)->unique();
            $table->string('git_sha', 40)->nullable();
            $table->text('release_notes')->nullable();
            $table->string('deployed_by')->nullable();
            $table->timestampTz('deployed_at');
            $table->boolean('is_current')->default(false);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_deployment_versions_deployed_at ON ops.deployment_versions (deployed_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.deployment_versions');
    }
};
