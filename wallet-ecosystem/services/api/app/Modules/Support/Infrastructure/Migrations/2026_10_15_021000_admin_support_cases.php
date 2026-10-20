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
        Schema::connection('platform')->create('support.admin_cases', function (Blueprint $t): void {
            $t->uuid('case_id')->primary();
            $t->timestampTz('escalated_at')->nullable();
            $t->timestampTz('deadline')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('senior_id')->nullable();
        });
        Schema::connection('platform')->create('support.admin_case_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('case_id')->index();
            $t->uuid('actor_id');
            $t->string('kind', 40);
            $t->jsonb('payload');
            $t->uuid('correlation_id');
            $t->string('request_key', 255)->nullable();
            $t->string('request_hash', 64)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['case_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('support.admin_case_entries');
        Schema::connection('platform')->dropIfExists('support.admin_cases');
    }
};
