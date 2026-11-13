<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    private function bounds(): array
    {
        return [
            'material_demands' => [['quantity_milli'], 'quantity_milli >= 0'],
            'stock_reservations' => [['remaining_milli'], 'remaining_milli >= 0'],
            'stock_allocations' => [['issued_milli', 'remaining_milli', 'remaining_value_micro'], 'issued_milli > 0 AND remaining_milli BETWEEN 0 AND issued_milli AND remaining_value_micro >= 0 AND (remaining_milli > 0 OR remaining_value_micro = 0)'],
            'purchase_lines' => [['quantity_milli', 'received_milli', 'unit_cost_micro'], 'quantity_milli > 0 AND received_milli BETWEEN 0 AND quantity_milli AND unit_cost_micro >= 0'],
            'stock_positions' => [['quantity_milli', 'reserved_milli', 'value_micro'], 'quantity_milli >= 0 AND reserved_milli BETWEEN 0 AND quantity_milli AND value_micro >= 0 AND (quantity_milli > 0 OR value_micro = 0)'],
        ];
    }

    private function identities(): array
    {
        return [
            'material_demands' => ['material_id', 'work_package_id'],
            'stock_reservations' => ['material_demand_id', 'stock_location_id'],
            'stock_allocations' => ['material_demand_id', 'stock_location_id', 'issued_milli'],
            'purchase_lines' => ['purchase_order_id', 'material_id', 'material_demand_id'],
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach ($this->bounds() as $table => [$columns, $condition]) {
                $types = implode(' OR ', array_map(fn (string $column): string => "typeof({$column}) != 'integer'", $columns));
                if (DB::table($table)->whereRaw("NOT ({$condition}) OR {$types}")->exists()) {
                    throw new RuntimeException("Resolve invalid inventory balances in {$table} before migrating.");
                }
            }
        }
        foreach ($this->bounds() as $table => [$columns, $condition]) {
            if (DB::getDriverName() === 'sqlite') {
                $qualified = preg_replace('/\b('.implode('|', $columns).')\b/', 'NEW.$1', $condition);
                $types = implode(' OR ', array_map(fn (string $column): string => "typeof(NEW.{$column}) != 'integer'", $columns));
                foreach (['INSERT', 'UPDATE'] as $verb) {
                    DB::statement("CREATE TRIGGER {$table}_operational_bounds_{$verb} BEFORE {$verb} ON {$table} WHEN NOT ({$qualified}) OR {$types} BEGIN SELECT RAISE(ABORT, 'Invalid inventory balance'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_operational_bounds CHECK ({$condition})");
            }
        }
        foreach ($this->identities() as $table => $columns) {
            if (DB::getDriverName() === 'sqlite') {
                $condition = implode(' OR ', array_map(fn (string $column): string => "NEW.{$column} IS NOT OLD.{$column}", $columns));
                DB::statement("CREATE TRIGGER {$table}_identity_guard BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Inventory basis is immutable'); END");
            } elseif (DB::getDriverName() === 'pgsql') {
                $condition = implode(' OR ', array_map(fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}", $columns));
                DB::unprepared("CREATE FUNCTION guard_{$table}_identity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF {$condition} THEN RAISE EXCEPTION 'Inventory basis is immutable'; END IF; RETURN NEW; END; $$");
                DB::statement("CREATE TRIGGER {$table}_identity_guard BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_{$table}_identity()");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->identities() as $table => $columns) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_identity_guard");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_identity_guard ON {$table}");
                DB::statement("DROP FUNCTION IF EXISTS guard_{$table}_identity()");
            }
        }
        foreach ($this->bounds() as $table => $bounds) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['INSERT', 'UPDATE'] as $verb) {
                    DB::statement("DROP TRIGGER IF EXISTS {$table}_operational_bounds_{$verb}");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_operational_bounds");
            }
        }
    }
};
