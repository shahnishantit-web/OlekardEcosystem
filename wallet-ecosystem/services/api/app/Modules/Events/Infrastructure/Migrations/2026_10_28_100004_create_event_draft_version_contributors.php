<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $schema = Schema::connection('community_services');
        $schema->create('community_services.event_draft_version_contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->unsignedInteger('version');
            $table->uuid('actor_id');
            $table->timestampTz('created_at');
            $table->unique(['event_id', 'version', 'actor_id'], 'event_draft_version_contributor_uq');
            $table->index(['event_id', 'version'], 'event_draft_version_contributor_version_idx');
            $table->index('actor_id', 'event_draft_version_contributor_actor_idx');
            $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
        });

        $events = DB::connection('community_services')->table('community_services.event_drafts')
            ->get(['id', 'version', 'created_by']);

        foreach ($events as $event) {
            $history = DB::connection('community_services')->table('community_services.event_draft_audit')
                ->where('event_id', $event->id)
                ->whereIn('action', ['created', 'updated'])
                ->orderBy('version')->orderBy('created_at')->get(['version', 'actor_id']);
            $contributors = [];
            $byVersion = [];
            foreach ($history as $entry) {
                $byVersion[(int) $entry->version][] = (string) $entry->actor_id;
            }

            for ($version = 1; $version <= (int) $event->version; $version++) {
                foreach ($byVersion[$version] ?? [] as $actorId) {
                    $contributors[$actorId] = true;
                }
                if ($version === 1) {
                    $contributors[(string) $event->created_by] = true;
                }

                foreach (array_keys($contributors) as $actorId) {
                    DB::connection('community_services')->table('community_services.event_draft_version_contributors')->insertOrIgnore([
                        'id' => (string) Uuid::uuid7(),
                        'event_id' => $event->id,
                        'version' => $version,
                        'actor_id' => $actorId,
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_draft_version_contributors');
    }
};
