<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE work_packages DROP CONSTRAINT covered_only');
            DB::statement("ALTER TABLE work_packages ADD CONSTRAINT package_kind CHECK (kind IN ('covered', 'diagnostic', 'maintenance', 'paid'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE work_packages DROP CONSTRAINT package_kind');
            DB::statement("ALTER TABLE work_packages ADD CONSTRAINT covered_only CHECK (kind IN ('covered', 'diagnostic', 'maintenance'))");
        }
    }
};
