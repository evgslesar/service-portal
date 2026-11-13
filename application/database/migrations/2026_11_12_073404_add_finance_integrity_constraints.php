<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'])) {
            return;
        }
        foreach ($this->bounds() as $table => [$columns, $condition]) {
            $types = DB::getDriverName() === 'sqlite' ? ' OR '.$this->invalidTypes($columns, '') : '';
            if (DB::table($table)->whereRaw("({$condition}) IS NOT TRUE{$types}")->exists()) {
                throw new RuntimeException("Resolve invalid finance amounts in {$table} before migrating.");
            }
        }
        foreach ($this->ownership() as $table => $condition) {
            if (DB::table($table.' as basis')->whereRaw('('.str_replace('@.', 'basis.', $condition).') IS NOT TRUE')->exists()) {
                throw new RuntimeException("Resolve invalid finance ownership in {$table} before migrating.");
            }
        }
        foreach ($this->bounds() as $table => [$columns, $condition]) {
            if (DB::getDriverName() === 'sqlite') {
                $qualified = preg_replace('/\b('.implode('|', [...$columns, 'kind', 'reversal_of_id']).')\b/', 'NEW.$1', $condition);
                $types = $this->invalidTypes($columns, 'NEW.');
                foreach ($table === 'refunds' ? ['INSERT', 'UPDATE'] : ['INSERT'] as $verb) {
                    DB::statement("CREATE TRIGGER {$table}_finance_amount_{$verb} BEFORE {$verb} ON {$table} WHEN ({$qualified}) IS NOT TRUE OR {$types} BEGIN SELECT RAISE(ABORT, 'Invalid finance amount'); END");
                }
            } else {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_finance_amount CHECK ({$condition})");
            }
        }
        foreach ($this->ownership() as $table => $condition) {
            $qualified = str_replace('@.', 'NEW.', $condition);
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("CREATE TRIGGER {$table}_finance_basis BEFORE INSERT ON {$table} WHEN ({$qualified}) IS NOT TRUE BEGIN SELECT RAISE(ABORT, 'Invalid finance basis'); END");
            } else {
                DB::unprepared("CREATE FUNCTION guard_finance_{$table}_basis() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF ({$qualified}) IS NOT TRUE THEN RAISE EXCEPTION 'Invalid finance basis'; END IF; RETURN NEW; END; $$");
                DB::statement("CREATE TRIGGER {$table}_finance_basis BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_finance_{$table}_basis()");
            }
        }
        $condition = implode(' OR ', array_map(fn (string $column): string => DB::getDriverName() === 'sqlite' ? "NEW.{$column} IS NOT OLD.{$column}" : "NEW.{$column} IS DISTINCT FROM OLD.{$column}", ['payment_receipt_id', 'actor_id', 'amount_kopecks', 'reason', 'created_at']));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER refunds_finance_identity BEFORE UPDATE ON refunds WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Refund basis is immutable'); END");
        } else {
            DB::unprepared("CREATE FUNCTION guard_finance_refund_identity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF {$condition} THEN RAISE EXCEPTION 'Refund basis is immutable'; END IF; RETURN NEW; END; $$");
            DB::statement('CREATE TRIGGER refunds_finance_identity BEFORE UPDATE ON refunds FOR EACH ROW EXECUTE FUNCTION guard_finance_refund_identity()');
        }
    }

    private function invalidTypes(array $columns, string $prefix): string
    {
        return implode(' OR ', array_map(fn (string $column): string => "typeof({$prefix}{$column}) != 'integer'", $columns));
    }

    private function bounds(): array
    {
        return [
            'estimates' => [['total_kopecks', 'revision'], 'total_kopecks > 0 AND revision >= 1'],
            'finance_documents' => [['total_kopecks'], "(kind = 'advance' AND total_kopecks > 0) OR (kind = 'actual' AND total_kopecks >= 0) OR (kind IN ('advance_credit', 'actual_credit') AND total_kopecks < 0)"],
            'payment_receipts' => [['amount_kopecks'], 'amount_kopecks > 0'],
            'payment_allocations' => [['amount_kopecks'], '(reversal_of_id IS NULL AND amount_kopecks > 0) OR (reversal_of_id IS NOT NULL AND amount_kopecks < 0)'],
            'advance_offsets' => [['amount_kopecks'], 'amount_kopecks != 0'],
            'refunds' => [['amount_kopecks'], 'amount_kopecks > 0'],
            'bank_receipt_reversals' => [['amount_kopecks'], 'amount_kopecks > 0'],
        ];
    }

    private function ownership(): array
    {
        return [
            'finance_documents' => "EXISTS (SELECT 1 FROM estimates e WHERE e.id = @.estimate_id AND e.service_request_id = @.service_request_id) AND (@.work_package_id IS NULL OR EXISTS (SELECT 1 FROM work_packages p WHERE p.id = @.work_package_id AND p.service_request_id = @.service_request_id)) AND ((@.kind IN ('advance', 'advance_credit') AND @.work_package_id IS NULL) OR (@.kind IN ('actual', 'actual_credit') AND @.work_package_id IS NOT NULL))",
            'payment_receipts' => 'EXISTS (SELECT 1 FROM service_requests r WHERE r.id = @.service_request_id AND r.organization_id = @.organization_id)',
            'payment_allocations' => "EXISTS (SELECT 1 FROM payment_receipts r JOIN finance_documents d ON d.service_request_id = r.service_request_id JOIN work_packages p ON p.service_request_id = r.service_request_id WHERE r.id = @.payment_receipt_id AND d.id = @.finance_document_id AND p.id = @.work_package_id AND d.kind IN ('advance', 'actual') AND (d.work_package_id IS NULL OR d.work_package_id = p.id)) AND (@.reversal_of_id IS NULL OR EXISTS (SELECT 1 FROM payment_allocations original WHERE original.id = @.reversal_of_id AND original.reversal_of_id IS NULL AND original.amount_kopecks > 0 AND original.payment_receipt_id = @.payment_receipt_id AND original.finance_document_id = @.finance_document_id AND original.work_package_id = @.work_package_id AND @.amount_kopecks >= -original.amount_kopecks))",
            'advance_offsets' => "EXISTS (SELECT 1 FROM finance_documents a JOIN finance_documents d ON d.service_request_id = a.service_request_id JOIN work_packages p ON p.service_request_id = a.service_request_id JOIN estimates ae ON ae.id = a.estimate_id JOIN estimates de ON de.id = d.estimate_id WHERE a.id = @.advance_document_id AND d.id = @.actual_document_id AND p.id = @.work_package_id AND a.kind = 'advance' AND d.kind = 'actual' AND d.work_package_id = p.id AND ae.group_key = de.group_key)",
            'finance_corrections' => "EXISTS (SELECT 1 FROM finance_documents original JOIN finance_documents credit ON credit.service_request_id = original.service_request_id JOIN estimates oe ON oe.id = original.estimate_id JOIN estimates ce ON ce.id = credit.estimate_id WHERE original.id = @.original_document_id AND credit.id = @.credit_document_id AND original.kind IN ('advance', 'actual') AND credit.kind = original.kind || '_credit' AND (credit.work_package_id = original.work_package_id OR (credit.work_package_id IS NULL AND original.work_package_id IS NULL)) AND oe.group_key = ce.group_key AND credit.total_kopecks >= -original.total_kopecks)",
            'advance_offset_reversals' => 'EXISTS (SELECT 1 FROM advance_offsets original JOIN advance_offsets reversal ON reversal.advance_document_id = original.advance_document_id AND reversal.actual_document_id = original.actual_document_id AND reversal.work_package_id = original.work_package_id WHERE original.id = @.original_offset_id AND reversal.id = @.reversal_offset_id AND original.amount_kopecks > 0 AND reversal.amount_kopecks < 0 AND reversal.amount_kopecks >= -original.amount_kopecks)',
            'bank_receipt_reversals' => "EXISTS (SELECT 1 FROM payment_receipts r WHERE r.id = @.payment_receipt_id AND r.source = 'bank' AND r.amount_kopecks = @.amount_kopecks)",
        ];
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS refunds_finance_identity');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS refunds_finance_identity ON refunds');
            DB::statement('DROP FUNCTION IF EXISTS guard_finance_refund_identity()');
        }
        foreach ($this->ownership() as $table => $condition) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_finance_basis");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_finance_basis ON {$table}");
                DB::statement("DROP FUNCTION IF EXISTS guard_finance_{$table}_basis()");
            }
        }
        foreach ($this->bounds() as $table => $bounds) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['INSERT', 'UPDATE'] as $verb) {
                    DB::statement("DROP TRIGGER IF EXISTS {$table}_finance_amount_{$verb}");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_finance_amount");
            }
        }
    }
};
