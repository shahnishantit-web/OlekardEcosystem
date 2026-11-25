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
        $schema = Schema::connection('community_services');
        if (! $schema->hasColumn('community_services.event_drafts', 'venue_city')) {
            $schema->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->string('venue_city', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        if ($schema->hasColumn('community_services.event_drafts', 'venue_city')) {
            $schema->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->dropColumn('venue_city');
            });
        }
    }
};
