<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_notifications', fn (Blueprint $t) => $t->string('audience')->default('general'));
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->boolean('email')->default(true);
            $t->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_notification_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('account_invitation_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('source_key')->unique();
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable()->index();
            $t->timestamp('locked_at')->nullable();
            $t->uuid('lease')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
        });
        Schema::create('notification_delivery_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_delivery_id')->constrained()->restrictOnDelete();
            $t->uuid('lease')->unique();
            $t->string('status');
            $t->string('failure_code')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
        Schema::table('portal_notifications', fn (Blueprint $t) => $t->dropColumn('audience'));
    }
};
