<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_config_change_drafts
             ADD COLUMN IF NOT EXISTS account_config_version_at_draft_time INTEGER NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_config_change_drafts
             DROP COLUMN IF EXISTS account_config_version_at_draft_time'
        );
    }
};
