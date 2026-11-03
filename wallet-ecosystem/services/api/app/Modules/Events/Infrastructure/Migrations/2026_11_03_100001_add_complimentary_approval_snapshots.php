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
        $db = Schema::connection('community_services');
        foreach (['event_invitations', 'event_free_registrations'] as $tableName) {
            $qualified = 'community_services.'.$tableName;
            if (! $db->hasColumn($qualified, 'approval_snapshot')) {
                $db->table($qualified, static function (Blueprint $table): void {
                    $table->jsonb('approval_snapshot')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        $db = Schema::connection('community_services');
        foreach (['event_free_registrations', 'event_invitations'] as $tableName) {
            $qualified = 'community_services.'.$tableName;
            if ($db->hasColumn($qualified, 'approval_snapshot')) {
                $db->table($qualified, static function (Blueprint $table): void {
                    $table->dropColumn('approval_snapshot');
                });
            }
        }
    }
};
