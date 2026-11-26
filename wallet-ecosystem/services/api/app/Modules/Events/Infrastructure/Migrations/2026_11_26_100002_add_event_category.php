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
        if (! $schema->hasColumn('community_services.event_drafts', 'event_category')) {
            $schema->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->string('event_category', 24)->default('other');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        if ($schema->hasColumn('community_services.event_drafts', 'event_category')) {
            $schema->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->dropColumn('event_category');
            });
        }
    }
};
