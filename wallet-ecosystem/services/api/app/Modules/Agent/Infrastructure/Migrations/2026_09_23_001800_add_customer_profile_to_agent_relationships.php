<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->table('agent.agent_customer_relationships', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('mobile_number', 20)->nullable();
            $table->string('national_id_type', 30)->nullable();
            $table->string('national_id_number', 50)->nullable();
            $table->string('customer_status', 30)->default('pending_kyc');
        });

        DB::connection('platform')->statement(
            'CREATE INDEX acr_mobile_number_idx ON agent.agent_customer_relationships (mobile_number)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX acr_customer_status_idx ON agent.agent_customer_relationships (customer_status)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->table('agent.agent_customer_relationships', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name', 'last_name', 'mobile_number',
                'national_id_type', 'national_id_number', 'customer_status',
            ]);
        });
    }
};
