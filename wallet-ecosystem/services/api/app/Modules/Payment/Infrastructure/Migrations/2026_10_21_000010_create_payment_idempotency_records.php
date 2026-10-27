<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';
    public function up(): void
    {
        DB::connection('payment')->statement('
            CREATE TABLE payment.payment_idempotency_records (
                id              UUID         NOT NULL,
                actor_id        VARCHAR(255) NOT NULL,
                scope           VARCHAR(64)  NOT NULL,
                idempotency_key VARCHAR(255) NOT NULL,
                request_hash    CHAR(64)     NOT NULL,
                response_status SMALLINT     NOT NULL,
                response_body   JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                expires_at      TIMESTAMPTZ  NOT NULL,
                PRIMARY KEY (id)
            )
        ');

        DB::connection('payment')->statement('
            CREATE UNIQUE INDEX payment_idempotency_records_actor_scope_key_idx
                ON payment.payment_idempotency_records (actor_id, scope, idempotency_key)
        ');

        DB::connection('payment')->statement('
            CREATE INDEX payment_idempotency_records_expires_at_idx
                ON payment.payment_idempotency_records (expires_at)
        ');
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.payment_idempotency_records'
        );
    }
};
