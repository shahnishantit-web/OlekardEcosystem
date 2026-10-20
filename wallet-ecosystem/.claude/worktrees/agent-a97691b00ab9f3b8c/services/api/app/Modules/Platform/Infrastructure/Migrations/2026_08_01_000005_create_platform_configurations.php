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
        Schema::connection('platform')->create('platform.configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('config_key', 255)->unique()->notNull();
            $table->text('config_value')->nullable();
            $table->string('config_type', 20)->notNull()->default('string');
            $table->boolean('is_tbd')->notNull()->default(false);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->notNull()->useCurrent();
            $table->timestamp('updated_at')->notNull()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.configurations');
    }
};
