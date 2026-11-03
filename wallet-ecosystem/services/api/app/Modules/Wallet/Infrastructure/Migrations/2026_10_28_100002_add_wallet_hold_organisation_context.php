<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        $schema = Schema::connection('wallet');
        if (! $schema->hasColumn('wallet.wallet_holds', 'organisation_id')) {
            $schema->table('wallet.wallet_holds', static function (Blueprint $table): void {
                $table->uuid('organisation_id')->nullable()->index();
            });
        }
        if (! $schema->hasColumn('wallet.wallet_capture_records', 'organisation_id')) {
            $schema->table('wallet.wallet_capture_records', static function (Blueprint $table): void {
                $table->uuid('organisation_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('wallet');
        foreach (['wallet.wallet_holds', 'wallet.wallet_capture_records'] as $tableName) {
            if ($schema->hasColumn($tableName, 'organisation_id')) {
                $schema->table($tableName, static function (Blueprint $table): void {
                    $table->dropColumn('organisation_id');
                });
            }
        }
    }
};
