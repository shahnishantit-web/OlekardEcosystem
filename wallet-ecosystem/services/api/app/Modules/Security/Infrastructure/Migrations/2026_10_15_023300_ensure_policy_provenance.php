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
        if (! Schema::connection('platform')->hasColumn('platform.configuration_values', 'last_updated_by')) {
            Schema::connection('platform')->table('platform.configuration_values', fn (Blueprint $t) => $t->string('last_updated_by', 36)->nullable());
        }
    }

    public function down(): void
    { /* Preserve provenance: the column is also owned by the existing E01 migration. */
    }
};
