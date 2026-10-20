<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->table('identity.sessions', fn (Blueprint $t) => $t->timestampTz('mfa_verified_at')->nullable());
    }

    public function down(): void
    {
        Schema::connection('identity')->table('identity.sessions', fn (Blueprint $t) => $t->dropColumn('mfa_verified_at'));
    }
};
