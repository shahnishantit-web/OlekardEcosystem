<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION multi_currency.lock_currency_definition_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'Currency code is immutable; create a separately approved currency migration.';
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended('currency:' || UPPER(OLD.code), 0));

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $function$
            SQL);

        DB::connection('platform')->statement(
            'DROP TRIGGER IF EXISTS currency_definition_coordination_lock ON multi_currency.currencies',
        );
        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER currency_definition_coordination_lock
            BEFORE UPDATE OF is_active, decimal_places OR DELETE
            ON multi_currency.currencies
            FOR EACH ROW
            EXECUTE FUNCTION multi_currency.lock_currency_definition_mutation()
            SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'DROP TRIGGER IF EXISTS currency_definition_coordination_lock ON multi_currency.currencies',
        );
        DB::connection('platform')->statement(
            'DROP FUNCTION IF EXISTS multi_currency.lock_currency_definition_mutation()',
        );
    }
};
