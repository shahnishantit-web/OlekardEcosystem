<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens platform.audit_events.ip_address from varchar(45) to varchar(64).
 *
 * The Identity module stores HMAC-SHA256(ip, secret) hashes in this column
 * (raw IPs must not be stored per BR-014). SHA-256 hex digests are 64 chars;
 * the original varchar(45) was sized for IPv6 text only.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE platform.audit_events ALTER COLUMN ip_address TYPE varchar(64)',
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE platform.audit_events ALTER COLUMN ip_address TYPE varchar(45)',
        );
    }
};
