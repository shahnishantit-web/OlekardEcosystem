<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'profile';

    public function up(): void
    {
        Schema::connection('profile')->create('profile.data_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('status', 20)->default('processing');
            $table->string('download_key', 500)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestampsTz();
        });

        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_data_exports_user_id_status ON profile.data_exports (user_id, status)'
        );
        DB::connection('profile')->statement(
            'CREATE INDEX ix_profile_data_exports_status ON profile.data_exports (status)'
        );
    }

    public function down(): void
    {
        Schema::connection('profile')->dropIfExists('profile.data_exports');
    }
};
