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
        Schema::connection('identity')->table('devices', function (Blueprint $table): void {
            $table->timestampTz('blocked_at')->nullable()->after('trusted_at');
            $table->string('blocked_reason', 255)->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::connection('identity')->table('devices', function (Blueprint $table): void {
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });
    }
};
