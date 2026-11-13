<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('provider_attempts', fn (Blueprint $table) => $table->uuid('candidate_provider_id')->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_attempts', fn (Blueprint $table) => $table->dropColumn('candidate_provider_id'));
    }
};
