<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->create('identity.credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('identity.users')
                ->onDelete('cascade');
            $table->string('credential_type', 20);
            $table->string('credential_hash', 255)->nullable();
            $table->text('biometric_public_key')->nullable();
            $table->string('fido2_credential_id', 500)->nullable();
            $table->text('fido2_public_key')->nullable();
            $table->bigInteger('fido2_sign_count')->default(0);
            $table->string('status', 20)->default('active');
            $table->integer('failed_attempt_count')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'credential_type']);
            $table->index('user_id');
        });

        DB::connection('identity')->statement(
            'CREATE INDEX identity_credentials_fido2_credential_id_idx ON identity.credentials (fido2_credential_id) WHERE fido2_credential_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.credentials');
    }
};
