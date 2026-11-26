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
        Schema::connection('community_services')->table('community_services.event_drafts', function (Blueprint $table): void {
            $table->index(
                ['organisation_id', 'created_by', 'updated_at'],
                'event_draft_creator_listing_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('community_services.event_drafts', function (Blueprint $table): void {
            $table->dropIndex('event_draft_creator_listing_idx');
        });
    }
};
