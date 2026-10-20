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
        Schema::connection('notification')->create('notification.notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->json('push_enabled_categories')->default('["financial","security","operational"]');
            $table->json('sms_enabled_categories')->default('["financial","security"]');
            $table->json('email_enabled_categories')->default('["security"]');
            $table->boolean('marketing_opted_in')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('notification')->dropIfExists('notification.notification_preferences');
    }
};
