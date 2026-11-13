<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_packages', function (Blueprint $table) {
            $table->unsignedInteger('execution_round')->default(1);
        });
        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedInteger('execution_round')->default(1);
            $table->text('scope_removal_reason')->nullable();
        });
        Schema::create('rework_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $table->foreignId('acceptance_round_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('classification');
            $table->text('evidence');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::table('portal_notifications', function (Blueprint $table) {
            $table->foreignId('work_package_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portal_notifications', fn (Blueprint $table) => $table->dropConstrainedForeignId('work_package_id'));
        Schema::dropIfExists('rework_assessments');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn(['execution_round', 'scope_removal_reason']));
        Schema::table('work_packages', fn (Blueprint $table) => $table->dropColumn('execution_round'));
    }
};
