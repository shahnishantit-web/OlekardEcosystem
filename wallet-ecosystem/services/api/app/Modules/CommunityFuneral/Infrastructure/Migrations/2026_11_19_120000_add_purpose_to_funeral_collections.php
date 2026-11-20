<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'community_services';

    public function up(): void
    {
        Schema::connection('community_services')->table('funeral_collections', function (Blueprint $table): void {
            $table->string('purpose', 500)->default('Community funeral assistance contribution');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('funeral_collections', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
