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
        foreach ([
            'event_format' => static fn (Blueprint $table) => $table->string('event_format', 16)->nullable(),
            'venue_country_code' => static fn (Blueprint $table) => $table->char('venue_country_code', 2)->nullable(),
        ] as $column => $definition) {
            if (! $schema->hasColumn('community_services.event_drafts', $column)) {
                $schema->table('community_services.event_drafts', $definition);
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        $columns = array_values(array_filter(
            ['event_format', 'venue_country_code'],
            static fn (string $column): bool => $schema->hasColumn('community_services.event_drafts', $column),
        ));
        if ($columns !== []) {
            $schema->table('community_services.event_drafts', static fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
