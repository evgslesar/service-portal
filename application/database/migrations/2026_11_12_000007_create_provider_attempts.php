<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('finance_document_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('refund_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('payment_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->uuid('command_key')->unique();
            $t->string('merchant_id');
            $t->bigInteger('amount_kopecks');
            $t->json('shares');
            $t->json('payload');
            $t->string('status')->default('queued');
            $t->string('provider_id')->nullable();
            $t->text('confirmation_url')->nullable();
            $t->unsignedInteger('send_count')->default(0);
            $t->timestamp('first_sent_at')->nullable();
            $t->timestamp('available_at')->index();
            $t->timestamp('locked_at')->nullable();
            $t->uuid('lease')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'provider_id']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('provider_attempt_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_attempt_id')->constrained()->restrictOnDelete();
            $t->string('status');
            $t->string('source');
            $t->string('failure_code')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('provider_webhooks', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->string('provider_id');
            $t->string('event');
            $t->string('status')->default('pending');
            $t->string('failure_code')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'provider_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhooks');
        Schema::dropIfExists('provider_attempt_events');
        Schema::dropIfExists('provider_attempts');
    }
};
