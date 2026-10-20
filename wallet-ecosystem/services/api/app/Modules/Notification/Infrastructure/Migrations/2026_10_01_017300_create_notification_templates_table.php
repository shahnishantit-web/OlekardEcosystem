<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'notification';

    public function up(): void
    {
        Schema::connection('notification')->create('notification.notification_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('category', 20);
            $table->text('subject_template');
            $table->text('body_template');
            $table->json('supported_channels');
            $table->json('variables');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('notification')->dropIfExists('notification.notification_templates');
    }
};
