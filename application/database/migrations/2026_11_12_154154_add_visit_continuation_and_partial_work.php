<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('continues_visit_id')->nullable()->unique()->constrained('visits')->restrictOnDelete();
            $table->text('continuation_reason')->nullable();
            $table->text('partial_work_scope')->nullable();
            $table->string('partial_work_fingerprint', 64)->nullable();
            $table->foreignId('partial_work_actor_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        $this->restoreSqliteExecutionIndex();
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('continues_visit_id');
            $table->dropConstrainedForeignId('partial_work_actor_id');
            $table->dropColumn(['continuation_reason', 'partial_work_scope', 'partial_work_fingerprint']);
        });
        $this->restoreSqliteExecutionIndex();
    }

    private function restoreSqliteExecutionIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS one_executing_visit');
            DB::statement("CREATE UNIQUE INDEX one_executing_visit ON visits (technician_id) WHERE status = 'in_progress'");
        }
    }
};
