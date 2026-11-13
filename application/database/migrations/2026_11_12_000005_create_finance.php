<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('kind')->primary();
            $t->unsignedBigInteger('next')->default(1);
        });
        Schema::table('service_requests', function (Blueprint $t) {
            $t->string('billing_mode')->nullable();
        });
        Schema::create('estimates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('group_key');
            $t->unsignedInteger('revision');
            $t->string('number')->unique();
            $t->json('header');
            $t->json('lines');
            $t->bigInteger('total_kopecks');
            $t->json('shares');
            $t->text('reason');
            $t->timestamps();
            $t->unique(['group_key', 'revision']);
        });
        Schema::create('estimate_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('estimate_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('decision');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('finance_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('estimate_id')->constrained()->restrictOnDelete();
            $t->foreignId('work_package_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('basis')->unique();
            $t->string('kind');
            $t->string('number')->unique();
            $t->json('header');
            $t->json('lines');
            $t->json('shares');
            $t->bigInteger('total_kopecks');
            $t->date('due_on');
            $t->timestamps();
        });
        Schema::create('payment_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('source');
            $t->string('receipt_key')->unique();
            $t->string('reference');
            $t->string('account');
            $t->date('paid_on');
            $t->string('payer');
            $t->text('evidence');
            $t->bigInteger('amount_kopecks');
            $t->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('finance_document_id')->constrained()->restrictOnDelete();
            $t->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->bigInteger('amount_kopecks');
            $t->foreignId('reversal_of_id')->nullable()->index()->constrained('payment_allocations')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('advance_offsets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('advance_document_id')->constrained('finance_documents')->restrictOnDelete();
            $t->foreignId('actual_document_id')->constrained('finance_documents')->restrictOnDelete();
            $t->foreignId('work_package_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount_kopecks');
            $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->bigInteger('amount_kopecks');
            $t->string('status')->default('approved');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('refund_confirmations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reference')->unique();
            $t->text('evidence');
            $t->timestamps();
        });
        foreach (['estimates', 'estimate_decisions', 'finance_documents', 'payment_receipts', 'payment_allocations', 'advance_offsets', 'refund_confirmations'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $verb) {
                    DB::statement("CREATE TRIGGER {$table}_immutable_{$verb} BEFORE {$verb} ON {$table} BEGIN SELECT RAISE(ABORT, 'Posted finance is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_stock_mutation()");
            }
        }
    }

    public function down(): void
    {
        foreach (['refund_confirmations', 'refunds', 'advance_offsets', 'payment_allocations', 'payment_receipts', 'finance_documents', 'estimate_decisions', 'estimates', 'document_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('service_requests', fn (Blueprint $t) => $t->dropColumn('billing_mode'));
    }
};
