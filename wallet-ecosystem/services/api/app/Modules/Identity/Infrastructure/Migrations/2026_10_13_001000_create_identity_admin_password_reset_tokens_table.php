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
        Schema::connection('identity')->create('identity.admin_password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->char('token_hash', 64);       // SHA-256 hex of the raw token — NEVER plaintext
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'used_at']);
            $table->index(['token_hash']);
            $table->index(['expires_at']);
        });

        DB::connection('identity')->statement(
            'ALTER TABLE identity.admin_password_reset_tokens '
            . 'ADD CONSTRAINT admin_password_reset_tokens_user_id_fk '
            . 'FOREIGN KEY (user_id) REFERENCES identity.users(id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.admin_password_reset_tokens');
    }
};
