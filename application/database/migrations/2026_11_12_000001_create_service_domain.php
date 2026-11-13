<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('address');
            $t->string('timezone')->default('Europe/Moscow');
            $t->string('contact_name')->nullable();
            $t->string('contact_phone')->nullable();
            $t->text('access_notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['id', 'organization_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('kind')->default('client');
            $t->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('roles')->nullable();
            $t->boolean('accept_work')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('session_version')->default(1);
            $t->text('totp_secret')->nullable();
            $t->timestamp('totp_confirmed_at')->nullable();
            $t->bigInteger('totp_last_step')->nullable();
            $t->json('recovery_codes')->nullable();
        });
        Schema::create('site_user', function (Blueprint $t) {
            $t->foreignId('site_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['site_id', 'user_id']);
        });
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('number')->unique();
            $t->string('title');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->json('covered_categories');
            $t->json('terms');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('contract_site', function (Blueprint $t) {
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('site_id')->constrained()->restrictOnDelete();
            $t->primary(['contract_id', 'site_id']);
        });
        Schema::create('service_requests', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('site_id');
            $t->foreign(['site_id', 'organization_id'])->references(['id', 'organization_id'])->on('sites')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('related_request_id')->nullable()->constrained('service_requests')->restrictOnDelete();
            $t->string('title');
            $t->text('description');
            $t->string('place')->nullable();
            $t->string('category')->nullable();
            $t->string('priority')->default('normal');
            $t->string('status')->default('new');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('closed_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'site_id', 'status', 'created_at']);
        });
        Schema::create('work_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('ordinal');
            $t->string('title');
            $t->text('scope');
            $t->string('category');
            $t->string('kind');
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->json('contract_snapshot');
            $t->foreignId('lead_id')->constrained('users')->restrictOnDelete();
            $t->json('checklist_template');
            $t->boolean('photo_required')->default(false);
            $t->string('status')->default('ready_to_schedule');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['service_request_id', 'ordinal']);
        });
        Schema::create('visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $t->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('planned_start');
            $t->timestampTz('planned_end');
            $t->timestampTz('actual_start')->nullable();
            $t->timestampTz('actual_end')->nullable();
            $t->string('status')->default('scheduled');
            $t->boolean('required')->default(true);
            $t->json('checklist');
            $t->text('report')->nullable();
            $t->unsignedInteger('labor_minutes')->nullable();
            $t->text('interruption_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['technician_id', 'status', 'planned_start']);
        });
        Schema::create('result_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('round');
            $t->foreignId('lead_id')->constrained('users')->restrictOnDelete();
            $t->text('summary');
            $t->json('contributions');
            $t->timestamps();
            $t->unique(['work_package_id', 'round']);
        });
        Schema::create('acceptance_rounds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $t->foreignId('result_snapshot_id')->unique()->constrained()->restrictOnDelete();
            $t->string('status')->default('pending');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('request_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('visit_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('visibility')->default('public');
            $t->string('type')->default('reply');
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('visit_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('path');
            $t->string('name');
            $t->string('mime');
            $t->unsignedInteger('size');
            $t->timestamps();
        });
        Schema::create('action_executions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_key');
            $t->string('action');
            $t->string('fingerprint', 64);
            $t->json('result');
            $t->timestamps();
            $t->unique(['actor_id', 'operation_key']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('service_request_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action');
            $t->json('data');
            $t->timestamps();
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('audit_event_id')->unique()->constrained()->restrictOnDelete();
            $t->string('status')->default('pending');
            $t->json('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('portal_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('outbox_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'outbox_event_id']);
        });
        DB::statement("CREATE UNIQUE INDEX one_executing_visit ON visits (technician_id) WHERE status = 'in_progress'");
        DB::statement("CREATE UNIQUE INDEX one_pending_acceptance ON acceptance_rounds (work_package_id) WHERE status = 'pending'");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE visits ADD CONSTRAINT no_visit_overlap EXCLUDE USING gist (technician_id WITH =, tstzrange(planned_start, planned_end, '[)') WITH &&) WHERE (status IN ('scheduled', 'in_progress'))");
            DB::statement('ALTER TABLE visits ADD CONSTRAINT valid_interval CHECK (planned_end > planned_start)');
            DB::statement("ALTER TABLE users ADD CONSTRAINT account_kind CHECK ((kind = 'staff' AND organization_id IS NULL AND accept_work = false) OR (kind = 'client' AND organization_id IS NOT NULL AND (roles IS NULL OR roles::text = '[]')))");
            DB::statement("ALTER TABLE work_packages ADD CONSTRAINT covered_only CHECK (kind IN ('covered', 'diagnostic', 'maintenance'))");
        }
    }

    public function down(): void
    {
        foreach (['portal_notifications', 'outbox_events', 'audit_events', 'action_executions', 'attachments', 'request_messages', 'acceptance_rounds', 'result_snapshots', 'visits', 'work_packages', 'service_requests', 'contract_site', 'contracts', 'site_user'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('organization_id');
            $t->dropColumn(['kind', 'roles', 'accept_work', 'active', 'session_version', 'totp_secret', 'totp_confirmed_at', 'totp_last_step', 'recovery_codes']);
        });
        Schema::dropIfExists('sites');
        Schema::dropIfExists('organizations');
    }
};
