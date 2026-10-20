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
        Schema::connection('platform')->create('security.finding_mutations', function (Blueprint $t): void {
            $t->uuid('request_key')->primary();
            $t->uuid('actor_id');
            $t->uuid('finding_id');
            $t->string('payload_hash', 64);
            $t->jsonb('response');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('security.finding_mutations');
    }
};
