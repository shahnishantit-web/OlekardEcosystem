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
        $table = 'community_services.event_drafts';
        $schema->table($table, static function (Blueprint $blueprint) use ($schema, $table): void {
            if (! $schema->hasColumn($table, 'description')) {
                $blueprint->text('description')->nullable();
            }
            if (! $schema->hasColumn($table, 'lineup')) {
                $blueprint->text('lineup')->nullable();
            }
            if (! $schema->hasColumn($table, 'gallery_image_urls')) {
                $blueprint->json('gallery_image_urls')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        $table = 'community_services.event_drafts';
        $schema->table($table, static function (Blueprint $blueprint) use ($schema, $table): void {
            foreach (['gallery_image_urls', 'lineup', 'description'] as $column) {
                if ($schema->hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
