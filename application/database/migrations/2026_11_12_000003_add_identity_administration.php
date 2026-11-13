<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable();
            $table->boolean('mfa_reset_required')->default(false);
        });
        Schema::create('identity_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('identity_locks')->insert(['id' => 1]);
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('account_version');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'consumed_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
        Schema::dropIfExists('identity_locks');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['permissions', 'mfa_reset_required']));
    }
};
