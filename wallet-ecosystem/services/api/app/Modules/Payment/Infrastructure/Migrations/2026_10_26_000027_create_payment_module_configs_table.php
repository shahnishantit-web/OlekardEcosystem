<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payment.payment_module_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module_key', 100)->unique()->comment('e.g. wallet_topup, bill_payment, p2p_transfer');
            $table->string('display_name', 128);
            $table->boolean('is_enabled')->default(true);
            $table->integer('display_order')->default(0);
            $table->text('description')->nullable();
            $table->string('updated_by_actor_id', 100)->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            'CREATE INDEX pmc_module_key_idx ON payment.payment_module_configs (module_key)'
        );

        // Seed known module keys from config if any
        /** @var string[] $knownModules */
        $knownModules = config('payment.known_modules', []);
        foreach ($knownModules as $index => $moduleKey) {
            DB::connection('payment')->table('payment_module_configs')->insert([
                'id'            => (string) Uuid::uuid7(),
                'module_key'    => $moduleKey,
                'display_name'  => ucwords(str_replace('_', ' ', (string) $moduleKey)),
                'is_enabled'    => true,
                'display_order' => (int) $index,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_module_configs');
    }
};
