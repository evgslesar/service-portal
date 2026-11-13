<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->bigInteger('default_rate_kopecks')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 100)->nullable();
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $verb) {
                DB::statement("CREATE TRIGGER material_catalog_bounds_{$verb} BEFORE {$verb} ON materials WHEN typeof(NEW.version) != 'integer' OR NEW.version < 1 OR (NEW.default_rate_kopecks IS NOT NULL AND (typeof(NEW.default_rate_kopecks) != 'integer' OR NEW.default_rate_kopecks < 0 OR NEW.default_rate_kopecks > 99999999999)) BEGIN SELECT RAISE(ABORT, 'Invalid material catalog value'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE materials ADD CONSTRAINT material_catalog_bounds CHECK (version >= 1 AND (default_rate_kopecks IS NULL OR default_rate_kopecks BETWEEN 0 AND 99999999999))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $verb) {
                DB::statement("DROP TRIGGER IF EXISTS material_catalog_bounds_{$verb}");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE materials DROP CONSTRAINT material_catalog_bounds');
        }
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['contact_name', 'contact_email', 'contact_phone']));
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn(['default_rate_kopecks', 'description', 'version']));
    }
};
