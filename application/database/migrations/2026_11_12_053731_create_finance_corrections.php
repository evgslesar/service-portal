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
        Schema::create('finance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_document_id')->constrained('finance_documents')->restrictOnDelete();
            $table->foreignId('credit_document_id')->unique()->constrained('finance_documents')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('advance_offset_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_offset_id')->constrained('advance_offsets')->restrictOnDelete();
            $table->foreignId('reversal_offset_id')->unique()->constrained('advance_offsets')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('bank_receipt_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_receipt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_kopecks');
            $table->text('reason');
            $table->text('evidence');
            $table->timestamps();
        });
        foreach (['finance_corrections', 'advance_offset_reversals', 'bank_receipt_reversals'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $verb) {
                    DB::statement("CREATE TRIGGER {$table}_immutable_{$verb} BEFORE {$verb} ON {$table} BEGIN SELECT RAISE(ABORT, 'Posted finance is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_stock_mutation()");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_receipt_reversals');
        Schema::dropIfExists('advance_offset_reversals');
        Schema::dropIfExists('finance_corrections');
    }
};
