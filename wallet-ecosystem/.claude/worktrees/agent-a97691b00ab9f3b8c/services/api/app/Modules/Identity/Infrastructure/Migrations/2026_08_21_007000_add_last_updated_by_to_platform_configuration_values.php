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
        Schema::connection('platform')->table('platform.configuration_values', static function (Blueprint $table): void {
            $table->string('last_updated_by', 36)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('platform.configuration_values', static function (Blueprint $table): void {
            $table->dropColumn('last_updated_by');
        });
    }
};
