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
        Schema::connection('platform')->create('integration.api_client_key_rotations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('api_client_id');
            $table->string('old_key_prefix', 8);
            $table->uuid('rotated_by_user_id')->nullable();
            $table->timestampTz('rotated_at');
            $table->text('reason')->nullable();
        });

        // Append-only: no updated_at, no delete cascade
        \Illuminate\Support\Facades\DB::connection('platform')->statement(
            'CREATE INDEX idx_api_client_rotations_client ON integration.api_client_key_rotations (api_client_id)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('integration.api_client_key_rotations');
    }
};
