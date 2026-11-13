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
        Schema::create('fiscal_receipt_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('number')->unique();
            $table->string('basis_type');
            $table->unsignedBigInteger('basis_id');
            $table->string('scenario');
            $table->bigInteger('amount_kopecks');
            $table->json('snapshot');
            $table->unique(['basis_type', 'basis_id']);
            $table->timestamps();
        });
        Schema::create('fiscal_receipt_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_receipt_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_key')->unique();
            $table->string('status');
            $table->json('mapping')->nullable();
            $table->json('evidence');
            $table->timestamps();
        });
        $basis = "(NEW.basis_type = 'payment' AND EXISTS (SELECT 1 FROM payment_receipts p WHERE p.id = NEW.basis_id AND p.service_request_id = NEW.service_request_id AND p.amount_kopecks = NEW.amount_kopecks AND ((p.source = 'bank' AND NEW.scenario = 'bank_transfer') OR (p.source = 'yookassa_sandbox' AND EXISTS (SELECT 1 FROM provider_attempts a JOIN finance_documents d ON d.id = a.finance_document_id WHERE a.payment_receipt_id = p.id AND a.kind = 'payment' AND ((d.kind = 'advance' AND NEW.scenario = 'advance') OR (d.kind = 'actual' AND NEW.scenario = 'final_payment')))))))
            OR (NEW.basis_type IN ('actual', 'credit') AND EXISTS (SELECT 1 FROM finance_documents d WHERE d.id = NEW.basis_id AND d.service_request_id = NEW.service_request_id AND d.total_kopecks = NEW.amount_kopecks AND ((NEW.basis_type = 'actual' AND d.kind = 'actual' AND NEW.scenario = 'final_settlement') OR (NEW.basis_type = 'credit' AND d.kind IN ('advance_credit', 'actual_credit') AND NEW.scenario = 'correction'))))
            OR (NEW.basis_type = 'offset' AND EXISTS (SELECT 1 FROM advance_offsets o JOIN finance_documents d ON d.id = o.actual_document_id WHERE o.id = NEW.basis_id AND d.service_request_id = NEW.service_request_id AND o.amount_kopecks = NEW.amount_kopecks AND ((o.amount_kopecks > 0 AND NEW.scenario = 'advance_offset') OR (o.amount_kopecks < 0 AND NEW.scenario = 'correction' AND EXISTS (SELECT 1 FROM advance_offset_reversals r WHERE r.reversal_offset_id = o.id)))))
            OR (NEW.basis_type = 'bank_reversal' AND NEW.scenario = 'correction' AND EXISTS (SELECT 1 FROM bank_receipt_reversals r JOIN payment_receipts p ON p.id = r.payment_receipt_id WHERE r.id = NEW.basis_id AND p.service_request_id = NEW.service_request_id AND -r.amount_kopecks = NEW.amount_kopecks))
            OR (NEW.basis_type = 'refund' AND NEW.scenario = 'refund' AND EXISTS (SELECT 1 FROM refunds r JOIN payment_receipts p ON p.id = r.payment_receipt_id JOIN refund_confirmations c ON c.refund_id = r.id WHERE r.id = NEW.basis_id AND r.status = 'succeeded' AND p.service_request_id = NEW.service_request_id AND -r.amount_kopecks = NEW.amount_kopecks))";
        $states = "NEW.status IN ('awaiting_configuration', 'awaiting_transport', 'not_required', 'provider_unknown', 'provider_pending', 'provider_registered', 'provider_failed')";
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER fiscal_receipt_records_basis BEFORE INSERT ON fiscal_receipt_records WHEN typeof(NEW.amount_kopecks) != 'integer' OR typeof(NEW.basis_id) != 'integer' OR NOT ({$basis}) BEGIN SELECT RAISE(ABORT, 'Invalid fiscal ledger basis'); END");
            DB::statement("CREATE TRIGGER fiscal_receipt_states_status BEFORE INSERT ON fiscal_receipt_states WHEN NOT ({$states}) BEGIN SELECT RAISE(ABORT, 'Invalid fiscal evidence status'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION validate_fiscal_ledger_basis() RETURNS trigger AS $$ BEGIN IF NOT ({$basis}) THEN RAISE EXCEPTION 'Invalid fiscal ledger basis'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER fiscal_receipt_records_basis BEFORE INSERT ON fiscal_receipt_records FOR EACH ROW EXECUTE FUNCTION validate_fiscal_ledger_basis()');
            DB::statement('ALTER TABLE fiscal_receipt_states ADD CONSTRAINT fiscal_evidence_status CHECK ('.str_replace('NEW.', '', $states).')');
        }
        foreach (['fiscal_receipt_records', 'fiscal_receipt_states'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $verb) {
                    DB::statement("CREATE TRIGGER {$table}_immutable_{$verb} BEFORE {$verb} ON {$table} BEGIN SELECT RAISE(ABORT, 'Fiscal evidence is immutable'); END");
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
        Schema::dropIfExists('fiscal_receipt_states');
        Schema::dropIfExists('fiscal_receipt_records');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS validate_fiscal_ledger_basis()');
        }
    }
};
