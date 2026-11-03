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
            'workflow_state' => fn (Blueprint $table) => $table->string('workflow_state', 30)->default('draft'),
            'sales_state' => fn (Blueprint $table) => $table->string('sales_state', 30)->default('not_published'),
            'event_reviewer_id' => fn (Blueprint $table) => $table->uuid('event_reviewer_id')->nullable(),
            'reviewed_version' => fn (Blueprint $table) => $table->unsignedInteger('reviewed_version')->nullable(),
            'price_approver_id' => fn (Blueprint $table) => $table->uuid('price_approver_id')->nullable(),
            'price_approved_version' => fn (Blueprint $table) => $table->unsignedInteger('price_approved_version')->nullable(),
            'published_version' => fn (Blueprint $table) => $table->unsignedInteger('published_version')->nullable(),
            'published_snapshot' => fn (Blueprint $table) => $table->jsonb('published_snapshot')->nullable(),
            'rejection_reason' => fn (Blueprint $table) => $table->string('rejection_reason', 1000)->nullable(),
        ] as $column => $definition) {
            if (! $schema->hasColumn('community_services.event_drafts', $column)) {
                $schema->table('community_services.event_drafts', $definition);
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        $schema->table('community_services.event_drafts', function (Blueprint $table) use ($schema): void {
            foreach ([
                'workflow_state', 'sales_state', 'event_reviewer_id', 'reviewed_version',
                'price_approver_id', 'price_approved_version', 'published_version',
                'published_snapshot', 'rejection_reason',
            ] as $column) {
                if ($schema->hasColumn('community_services.event_drafts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
