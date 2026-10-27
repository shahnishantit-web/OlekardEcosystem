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
        Schema::connection('community_services')->table('funeral_closures', function (Blueprint $table): void {
            $table->uuid('carry_forward_requested_by')->nullable();
            $table->timestampTz('carry_forward_requested_at')->nullable();
            $table->timestampTz('carry_forward_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('funeral_closures', function (Blueprint $table): void {
            $table->dropColumn(['carry_forward_requested_by', 'carry_forward_requested_at', 'carry_forward_approved_at']);
        });
    }
};
