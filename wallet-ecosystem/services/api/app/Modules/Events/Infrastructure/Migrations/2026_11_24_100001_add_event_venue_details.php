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
            'venue_name' => static fn (Blueprint $table) => $table->string('venue_name', 200)->nullable(),
            'venue_address' => static fn (Blueprint $table) => $table->string('venue_address', 500)->nullable(),
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
            ['venue_name', 'venue_address'],
            static fn (string $column): bool => $schema->hasColumn('community_services.event_drafts', $column),
        ));
        if ($columns !== []) {
            $schema->table('community_services.event_drafts', static fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
