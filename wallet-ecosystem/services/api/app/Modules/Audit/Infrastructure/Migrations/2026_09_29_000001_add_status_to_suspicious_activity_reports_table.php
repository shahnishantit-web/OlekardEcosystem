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
        Schema::connection('platform')->table('audit.suspicious_activity_reports', function (Blueprint $table): void {
            $table->string('status', 16)->default('draft')->after('reason_narrative');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('audit.suspicious_activity_reports', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
