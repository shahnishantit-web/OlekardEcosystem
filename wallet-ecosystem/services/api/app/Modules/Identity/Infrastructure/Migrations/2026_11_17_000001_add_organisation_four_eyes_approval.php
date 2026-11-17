<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->table('identity.organisations', function (Blueprint $table): void {
            $table->text('reviewer_comments')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->text('approver_comments')->nullable();
        });
        foreach (['organisation.registry.read', 'organisation.registry.approve'] as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'slug' => $slug,
                'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                'module' => 'identity', 'is_system_permission' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('identity')->table('identity.organisations', function (Blueprint $table): void {
            $table->dropColumn(['reviewer_comments', 'approved_by', 'approver_comments']);
        });
        DB::connection('identity')->table('identity.permissions')->whereIn('slug', ['organisation.registry.read', 'organisation.registry.approve'])->delete();
    }
};
