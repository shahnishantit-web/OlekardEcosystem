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
        Schema::connection('platform')->create('platform.configuration_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('config_key', 255)->notNull();
            $table->text('config_value')->nullable();
            $table->string('value_type', 20)->notNull()->default('string');
            $table->string('status', 20)->notNull()->default('active');
            $table->integer('version')->notNull()->default(1);
            $table->boolean('is_tbd')->notNull()->default(false);
            $table->text('description')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamp('created_at')->notNull()->useCurrent();
            $table->timestamp('updated_at')->notNull()->useCurrent();

            $table->unique(['config_key', 'version'], 'configuration_values_key_version_unique');
            $table->index(['config_key', 'status', 'effective_from'], 'configuration_values_key_status_from_idx');
            $table->index(['status', 'effective_from'], 'configuration_values_status_from_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.configuration_values');
    }
};
