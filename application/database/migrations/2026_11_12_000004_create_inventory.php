<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locks', fn (Blueprint $t) => $t->unsignedInteger('id')->primary());
        DB::table('stock_locks')->insert(['id' => 1]);
        Schema::table('visits', function (Blueprint $t) {
            $t->json('usage_draft')->nullable();
            $t->json('stock_document_ids')->nullable();
        });
        Schema::create('materials', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('unit');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stock_locations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind');
            $t->foreignId('custodian_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stock_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity_milli')->default(0);
            $t->bigInteger('reserved_milli')->default(0);
            $t->bigInteger('value_micro')->default(0);
            $t->unique(['stock_location_id', 'material_id']);
            $t->timestamps();
        });
        Schema::create('material_demands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->bigInteger('quantity_milli');
            $t->string('status')->default('approved');
            $t->boolean('billable')->default(false);
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_demand_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $t->bigInteger('remaining_milli');
            $t->timestamps();
        });
        Schema::create('stock_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_demand_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $t->bigInteger('issued_milli');
            $t->bigInteger('remaining_milli');
            $t->bigInteger('remaining_value_micro');
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('details');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('number')->unique();
            $t->string('status')->default('ordered');
            $t->date('expected_on');
            $t->text('reason');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('purchase_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_demand_id')->nullable()->constrained()->restrictOnDelete();
            $t->bigInteger('quantity_milli');
            $t->bigInteger('received_milli')->default(0);
            $t->bigInteger('unit_cost_micro');
            $t->timestamps();
            $t->unique(['purchase_order_id', 'material_id']);
        });
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->string('kind');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('visit_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('stock_reservation_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('stock_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('purchase_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('stock_documents')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('stock_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_document_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity_delta');
            $t->bigInteger('reserved_delta');
            $t->bigInteger('value_delta');
            $t->timestamps();
            $t->index(['stock_location_id', 'material_id']);
        });
        foreach (['sqlite', 'pgsql'] as $driver) {
            if (DB::getDriverName() !== $driver) {
                continue;
            }
            if ($driver === 'sqlite') {
                DB::statement("CREATE TRIGGER stock_position_bounds_insert BEFORE INSERT ON stock_positions WHEN NEW.quantity_milli < 0 OR NEW.reserved_milli < 0 OR NEW.reserved_milli > NEW.quantity_milli OR NEW.value_micro < 0 BEGIN SELECT RAISE(ABORT, 'Invalid stock balance'); END");
                DB::statement("CREATE TRIGGER stock_position_bounds_update BEFORE UPDATE ON stock_positions WHEN NEW.quantity_milli < 0 OR NEW.reserved_milli < 0 OR NEW.reserved_milli > NEW.quantity_milli OR NEW.value_micro < 0 BEGIN SELECT RAISE(ABORT, 'Invalid stock balance'); END");
                foreach (['stock_entries', 'stock_documents'] as $table) {
                    foreach (['UPDATE', 'DELETE'] as $verb) {
                        DB::statement("CREATE TRIGGER {$table}_immutable_{$verb} BEFORE {$verb} ON {$table} BEGIN SELECT RAISE(ABORT, 'Posted stock is immutable'); END");
                    }
                }
            } else {
                DB::statement('ALTER TABLE stock_positions ADD CONSTRAINT stock_position_bounds CHECK (quantity_milli >= 0 AND reserved_milli >= 0 AND reserved_milli <= quantity_milli AND value_micro >= 0)');
                DB::statement("CREATE FUNCTION prevent_stock_mutation() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Posted stock is immutable''; END;'");
                foreach (['stock_entries', 'stock_documents'] as $table) {
                    DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_stock_mutation()");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['stock_entries', 'stock_documents', 'purchase_lines', 'purchase_orders', 'suppliers', 'stock_allocations', 'stock_reservations', 'material_demands', 'stock_positions', 'stock_locations', 'materials'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS prevent_stock_mutation()');
        }
        Schema::table('visits', fn (Blueprint $t) => $t->dropColumn(['usage_draft', 'stock_document_ids']));
        Schema::dropIfExists('stock_locks');
    }
};
