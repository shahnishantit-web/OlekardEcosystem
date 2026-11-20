<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('community_services')->table('funeral_collections', function (Blueprint $table): void {
            $table->uuid('target_household_id')->nullable()->index();
        });
        Schema::connection('notification')->table('notification.notifications', function (Blueprint $table): void {
            $table->string('deep_link', 500)->nullable();
        });
        DB::connection('notification')->table('notification.notification_templates')
            ->where('key', 'funeral.collection.opened')->update([
                'subject_template' => 'Funeral collection for {{community_name}} is open',
                'body_template' => '{{organisation_name}} · {{community_name}}. {{purpose}}. Contribution requested: {{amount}}. Open this collection in OleKard: /services/funeral?collection_id={{collection_id}}',
                'variables' => json_encode(['organisation_name', 'community_name', 'purpose', 'amount', 'collection_id']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('funeral_collections', function (Blueprint $table): void {
            $table->dropColumn('target_household_id');
        });
        Schema::connection('notification')->table('notification.notifications', function (Blueprint $table): void {
            $table->dropColumn('deep_link');
        });
    }
};
