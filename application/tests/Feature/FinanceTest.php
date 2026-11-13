<?php

namespace Tests\Feature;

use App\Actions\Finance\Finance;
use App\Actions\Inventory\Inventory;
use App\Actions\ServiceRequests\Workflow;
use App\Livewire\Billing;
use App\Livewire\RequestDetail;
use App\Livewire\Workspace;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkPackage;
use Database\Seeders\BrowserVerificationSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private Finance $finance;

    private User $staff;

    private User $dispatcher;

    private User $tech;

    private User $client;

    private ServiceRequest $requestRecord;

    private WorkPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['portal.finance.approved' => true, 'portal.finance.legal_header' => 'Test seller', 'portal.finance.tax_label' => 'Test tax configuration']);
        $this->finance = app(Finance::class);
        $this->staff = User::factory()->create(['kind' => 'staff', 'roles' => ['finance']]);
        $this->dispatcher = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher']]);
        $this->tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $org = DB::table('organizations')->insertGetId(['name' => 'Test buyer']);
        $site = Site::create(['organization_id' => $org, 'name' => 'Test site', 'address' => 'Test address']);
        $this->client = User::factory()->create(['kind' => 'client', 'organization_id' => $org, 'accept_work' => true]);
        $this->requestRecord = ServiceRequest::create(['site_id' => $site->id, 'organization_id' => $org, 'author_id' => $this->client->id, 'number' => 'FINANCE-TEST', 'title' => 'Paid repair', 'description' => 'Test repair']);
        $contract = Contract::create(['organization_id' => $org, 'number' => 'FIN-CONTRACT', 'title' => 'Coverage', 'starts_on' => now()->subYear(), 'ends_on' => now()->addYear(), 'covered_categories' => ['plumbing'], 'terms' => []]);
        $this->package = WorkPackage::create(['service_request_id' => $this->requestRecord->id, 'ordinal' => 1, 'contract_id' => $contract->id, 'contract_snapshot' => [], 'title' => 'Paid repair', 'scope' => 'Repair', 'category' => 'plumbing', 'kind' => 'paid', 'lead_id' => $this->tech->id, 'checklist_template' => [['text' => 'Safety', 'required' => true]], 'photo_required' => false]);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function line(?WorkPackage $package = null, string $quantity = '100', string $rate = '10'): array
    {
        return ['package_id' => ($package ?? $this->package)->id, 'kind' => 'labor', 'material_id' => null, 'description' => 'Repair labor', 'quantity' => $quantity, 'rate' => $rate];
    }

    private function estimate(array $lines = [], string $mode = 'independent_packages'): int
    {
        return $this->finance->issue($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, $mode, $lines ?: [$this->line()], 'Verified scope', $this->key())['id'];
    }

    private function approved(): int
    {
        return $this->finance->decideEstimate($this->client, $this->estimate(), 'approved', '', $this->key())['invoice_id'];
    }

    private function bank(string $amount = '500', string $reference = 'BANK-1'): int
    {
        return $this->finance->recordBank($this->staff, $this->requestRecord->id, $this->bankInput($amount, $reference), $this->key())['id'];
    }

    private function bankInput(string $amount, string $reference): array
    {
        return ['reference' => $reference, 'account' => 'Test account', 'paid_on' => now()->format('Y-m-d'), 'payer' => 'Test buyer', 'evidence' => 'Verified statement', 'amount' => $amount];
    }

    private function allocate(int $receipt, int $invoice, string $amount): int
    {
        return $this->finance->allocate($this->staff, $receipt, $invoice, $this->package->id, $amount, 'Verified allocation', $this->key())['id'];
    }

    private function readyResult(int $minutes = 100): int
    {
        $w = app(Workflow::class);
        $visit = $w->schedule($this->dispatcher, $this->package->id, $this->package->fresh()->version, ['technician_id' => $this->tech->id, 'start' => now()->addDay()->timezone($this->requestRecord->site->timezone)->format('Y-m-d\TH:i'), 'end' => now()->addDay()->addHour()->timezone($this->requestRecord->site->timezone)->format('Y-m-d\TH:i')], $this->key())['id'];
        $w->visit($this->tech, $visit, 1, 'start', [], $this->key());
        $w->visit($this->tech, $visit, 2, 'finish_submit', ['report' => 'Repair completed', 'checks' => [true], 'labor_minutes' => $minutes, 'usage_confirmed' => true, 'usage' => []], $this->key());

        return $this->package->rounds()->latest('id')->value('id');
    }

    private function accept(int $round): void
    {
        app(Workflow::class)->decide($this->client, $this->requestRecord->id, [$round], 'accepted', '', $this->key());
    }

    private function diagnosticRound(): int
    {
        $p = WorkPackage::create([...$this->package->only(['service_request_id', 'contract_id', 'contract_snapshot', 'scope', 'category', 'lead_id', 'checklist_template', 'photo_required']), 'ordinal' => 2, 'title' => 'Diagnosis', 'kind' => 'diagnostic']);
        $w = app(Workflow::class);
        $visit = $w->schedule($this->dispatcher, $p->id, 1, ['technician_id' => $this->tech->id, 'start' => now($this->requestRecord->site->timezone)->addHours(1)->format('Y-m-d\TH:i'), 'end' => now($this->requestRecord->site->timezone)->addHours(2)->format('Y-m-d\TH:i')], $this->key())['id'];
        $w->visit($this->tech, $visit, 1, 'start', [], $this->key());
        $w->visit($this->tech, $visit, 2, 'finish_submit', ['report' => 'Diagnosis complete', 'checks' => [true], 'labor_minutes' => 10, 'usage_confirmed' => true], $this->key());

        return $p->rounds()->value('id');
    }

    public function test_printable_finance_documents_keep_original_content_and_restrict_access(): void
    {
        $this->withSession(['account_version' => 1, 'mfa_passed' => true]);
        foreach ([$this->client, $this->staff, $this->dispatcher, $this->tech] as $user) {
            $user->forceFill(['totp_confirmed_at' => now()])->save();
        }
        $estimate = $this->estimate();
        $invoice = $this->finance->decideEstimate($this->client, $estimate, 'approved', '', $this->key())['invoice_id'];
        config(['portal.finance.legal_header' => 'Changed seller']);
        $url = route('documents.estimate', ['id' => $this->requestRecord->id, 'estimate' => $estimate]);
        $this->actingAs($this->client)->get($url)->assertOk()->assertSee('Test seller')->assertDontSee('Changed seller')->assertSee('Repair labor');
        $this->actingAs($this->staff)->withSession(['mfa_passed' => true])->get(route('documents.invoice', ['id' => $this->requestRecord->id, 'invoice' => $invoice]))->assertOk()->assertSee('500.00')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->tech)->get($url)->assertNotFound();
        $this->actingAs($this->dispatcher)->withSession(['mfa_passed' => true])->get($url)->assertNotFound();
        $outsider = User::factory()->create(['kind' => 'client', 'organization_id' => null, 'accept_work' => true, 'totp_confirmed_at' => now()]);
        $this->actingAs($outsider)->get($url)->assertNotFound();
        $this->actingAs($this->client)->get(route('documents.invoice', ['id' => $this->requestRecord->id + 100, 'invoice' => $invoice]))->assertNotFound();
        $this->assertDatabaseCount('finance_documents', 1);
        $this->assertDatabaseCount('payment_receipts', 0);
    }

    public function test_composite_diagnostic_and_estimate_decision_is_explicit_and_idempotent(): void
    {
        $round = $this->diagnosticRound();
        $estimate = $this->estimate();
        $key = $this->key();
        $w = app(Workflow::class);
        $result = $w->acceptDiagnosticAndEstimate($this->client, $this->requestRecord->id, $round, $estimate, $key);
        $this->assertSame($result, $w->acceptDiagnosticAndEstimate($this->client, $this->requestRecord->id, $round, $estimate, $key));
        $this->assertDatabaseHas('acceptance_rounds', ['id' => $round, 'status' => 'accepted']);
        $this->assertDatabaseHas('estimate_decisions', ['estimate_id' => $estimate, 'decision' => 'approved']);
        $this->assertDatabaseCount('finance_documents', 1);
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertFalse($this->finance->executionReady($this->package));
    }

    public function test_stale_composite_decision_rolls_back_diagnostic_acceptance(): void
    {
        $round = $this->diagnosticRound();
        $estimate = $this->estimate();
        $page = Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $this->requestRecord->id]);
        $this->finance->decideEstimate($this->client, $estimate, 'rejected', 'Change scope', $this->key());
        $this->estimate([$this->line(quantity: '80')]);
        $page->call('acceptDiagnosticAndEstimate', $round, $estimate)->assertHasErrors('finance');
        $this->assertDatabaseHas('acceptance_rounds', ['id' => $round, 'status' => 'pending']);
        $this->assertDatabaseCount('finance_documents', 0);
        $this->assertSame(0, DB::table('action_executions')->where('action', 'diagnostic.estimate_accept')->count());
    }

    public function test_composite_decision_rejects_cross_package_estimate(): void
    {
        $roundA = $this->diagnosticRound();
        $diagnosticB = WorkPackage::create([...$this->package->only(['service_request_id', 'contract_id', 'contract_snapshot', 'scope', 'category', 'lead_id', 'checklist_template', 'photo_required']), 'ordinal' => 3, 'title' => 'Second diagnosis', 'kind' => 'diagnostic']);
        $w = app(Workflow::class);
        $visitB = $w->schedule($this->dispatcher, $diagnosticB->id, 1, ['technician_id' => $this->tech->id, 'start' => now($this->requestRecord->site->timezone)->addHours(3)->format('Y-m-d\TH:i'), 'end' => now($this->requestRecord->site->timezone)->addHours(4)->format('Y-m-d\TH:i')], $this->key())['id'];
        $w->visit($this->tech, $visitB, 1, 'start', [], $this->key());
        $w->visit($this->tech, $visitB, 2, 'finish_submit', ['report' => 'Diagnosis B complete', 'checks' => [true], 'labor_minutes' => 10, 'usage_confirmed' => true], $this->key());
        $roundB = $diagnosticB->rounds()->value('id');
        $estimateA = $this->estimate();
        $secondPackage = $this->package->replicate();
        $secondPackage->ordinal = 4;
        $secondPackage->title = 'Second repair';
        $secondPackage->save();
        $estimateB = $this->finance->issue($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, 'independent_packages', [['package_id' => $secondPackage->id, 'kind' => 'labor', 'material_id' => null, 'description' => 'Second repair', 'quantity' => '1', 'rate' => '200']], 'Verified scope', $this->key())['id'];
        $this->expectException(ValidationException::class);
        $w->acceptDiagnosticAndEstimate($this->client, $this->requestRecord->id, $roundA, $estimateB, $this->key());
    }

    public function test_interrupted_paid_work_is_continued_without_losing_time_or_raising_cap(): void
    {
        $invoice = $this->approved();
        $this->allocate($this->bank(), $invoice, '500');
        $w = app(Workflow::class);
        $tz = $this->requestRecord->site->timezone;
        $input = ['technician_id' => $this->tech->id, 'start' => now($tz)->addDay()->format('Y-m-d\TH:i'), 'end' => now($tz)->addDay()->addHour()->format('Y-m-d\TH:i')];
        $visit = $w->schedule($this->dispatcher, $this->package->id, $this->package->fresh()->version, $input, $this->key())['id'];
        $w->visit($this->tech, $visit, 1, 'start', [], $this->key());
        $report = ['report' => 'Prepared repair', 'checks' => [false], 'labor_minutes' => 30, 'usage_confirmed' => true, 'reason' => 'Access lost'];
        $w->visit($this->tech, $visit, 2, 'interrupt', $report, $this->key());
        $next = $w->schedule($this->dispatcher, $this->package->id, $this->package->fresh()->version, [...$input, 'continues_visit_id' => $visit, 'continues_visit_version' => 3, 'continuation_reason' => 'Finish approved scope'], $this->key())['id'];
        $w->visit($this->tech, $next, 1, 'start', [], $this->key());
        $w->visit($this->tech, $next, 2, 'finish_submit', [...$report, 'report' => 'Repair complete', 'checks' => [true], 'labor_minutes' => 80], $this->key());
        $this->accept($this->package->rounds()->latest('id')->value('id'));
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'interrupted', 'required' => true, 'labor_minutes' => 30]);
        $this->assertDatabaseHas('finance_documents', ['kind' => 'actual', 'total_kopecks' => 100000]);
        $this->assertSame(110, (int) $this->package->visits()->sum('labor_minutes'));
        $this->assertDatabaseCount('estimates', 1);
    }

    public function test_accountant_gate_rolls_back_issuance_and_number(): void
    {
        config(['portal.finance.approved' => false]);
        try {
            $this->estimate();
            $this->fail('Unapproved configuration accepted');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('estimates', 0);
        $this->assertDatabaseCount('document_sequences', 0);
        $this->assertNull($this->requestRecord->fresh()->billing_mode);
    }

    public function test_estimate_line_rounding_and_largest_remainder_are_exact(): void
    {
        $this->assertSame([2 => 1, 7 => 1, 9 => 0], Finance::proportional([9 => 1, 7 => 1, 2 => 1], 2));
        $this->assertSame([1 => 3000000, 2 => 2000000], Finance::proportional([1 => 6000000, 2 => 4000000], 5000000));
        $id = $this->estimate([$this->line(quantity: '0.500', rate: '0.03')]);
        $estimate = DB::table('estimates')->find($id);
        $this->assertSame(2, $estimate->total_kopecks);
        $this->assertSame(1, array_sum(json_decode($estimate->shares, true)));
    }

    public function test_approval_and_command_retry_create_one_advance(): void
    {
        $id = $this->estimate();
        $key = $this->key();
        $first = $this->finance->decideEstimate($this->client, $id, 'approved', '', $key);
        $this->assertSame($first, $this->finance->decideEstimate($this->client, $id, 'approved', '', $key));
        $this->assertDatabaseCount('estimate_decisions', 1);
        $this->assertDatabaseCount('finance_documents', 1);
        $this->assertDatabaseHas('finance_documents', ['id' => $first['invoice_id'], 'total_kopecks' => 50000]);
        $this->assertFalse($this->finance->executionReady($this->package));
    }

    public function test_rejection_requires_reason_and_revision_preserves_original(): void
    {
        $first = $this->estimate();
        try {
            $this->finance->decideEstimate($this->client, $first, 'rejected', '', $this->key());
            $this->fail('Missing reason accepted');
        } catch (ValidationException) {
        }
        $this->finance->decideEstimate($this->client, $first, 'rejected', 'Reduce scope', $this->key());
        $second = $this->estimate([$this->line(quantity: '80')]);
        $this->assertDatabaseHas('estimates', ['id' => $first, 'total_kopecks' => 100000, 'revision' => 1]);
        $this->assertDatabaseHas('estimates', ['id' => $second, 'total_kopecks' => 80000, 'revision' => 2]);
        $this->expectException(ValidationException::class);
        $this->finance->decideEstimate($this->client, $first, 'approved', '', $this->key());
    }

    public function test_client_cannot_approve_other_organization_estimate(): void
    {
        $id = $this->estimate();
        $org = DB::table('organizations')->insertGetId(['name' => 'Other buyer']);
        $other = User::factory()->create(['organization_id' => $org, 'accept_work' => true]);
        $this->expectException(ModelNotFoundException::class);
        $this->finance->decideEstimate($other, $id, 'approved', '', $this->key());
    }

    public function test_material_approval_creates_billable_demand_without_stock_movement(): void
    {
        $warehouse = User::factory()->create(['kind' => 'staff', 'roles' => ['warehouse']]);
        $material = app(Inventory::class)->material($warehouse, ['code' => 'FIN-M1', 'name' => 'Test pipe', 'unit' => 'm', 'reason' => 'Catalog entry'], $this->key())['id'];
        $line = [...$this->line(quantity: '2'), 'kind' => 'material', 'material_id' => $material];
        $id = $this->estimate([$this->line(), $line]);
        $this->finance->decideEstimate($this->client, $id, 'approved', '', $this->key());
        $this->assertDatabaseHas('material_demands', ['work_package_id' => $this->package->id, 'material_id' => $material, 'billable' => true, 'quantity_milli' => 2000]);
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_partial_funding_blocks_execution_until_advance_is_allocated(): void
    {
        $invoice = $this->approved();
        $receipt = $this->bank();
        $this->allocate($receipt, $invoice, '499.99');
        $this->assertFalse($this->package->fresh()->allowsExecution());
        $this->allocate($receipt, $invoice, '0.01');
        $this->assertTrue($this->package->fresh()->allowsExecution());
        $this->assertGreaterThan(0, $this->readyResult());
    }

    public function test_bank_retry_and_duplicate_reference_do_not_duplicate_money(): void
    {
        $input = $this->bankInput('500', 'BANK-1');
        $key = $this->key();
        $first = $this->finance->recordBank($this->staff, $this->requestRecord->id, $input, $key);
        $this->assertSame($first, $this->finance->recordBank($this->staff, $this->requestRecord->id, $input, $key));
        try {
            $this->bank();
            $this->fail('Duplicate bank reference accepted');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertSame(50000, $this->finance->balance($this->requestRecord->id)['received']);
    }

    public function test_allocation_cannot_exceed_confirmed_funds_or_package_share(): void
    {
        $invoice = $this->approved();
        $receipt = $this->bank('501');
        $this->expectException(ValidationException::class);
        $this->allocate($receipt, $invoice, '500.01');
    }

    public function test_actual_invoice_is_once_only_capped_and_requires_settlement(): void
    {
        $invoice = $this->approved();
        $receipt = $this->bank();
        $this->allocate($receipt, $invoice, '500');
        $round = $this->readyResult(120);
        $key = $this->key();
        $w = app(Workflow::class);
        $first = $w->decide($this->client, $this->requestRecord->id, [$round], 'accepted', '', $key);
        $this->assertSame($first, $w->decide($this->client, $this->requestRecord->id, [$round], 'accepted', '', $key));
        $this->assertDatabaseHas('finance_documents', ['kind' => 'actual', 'total_kopecks' => 100000]);
        $this->assertDatabaseCount('advance_offsets', 1);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'actual', 'scenario' => 'final_settlement', 'amount_kopecks' => 100000, 'actor_id' => $this->client->id]);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'offset', 'scenario' => 'advance_offset', 'amount_kopecks' => 50000, 'actor_id' => $this->client->id]);
        $this->assertDatabaseCount('fiscal_receipt_records', 3);
        $this->assertSame(50000, $this->finance->balance($this->requestRecord->id)['debt']);
        $this->assertSame('awaiting_settlement', $this->requestRecord->fresh()->status);
        Livewire::actingAs($this->staff)->test(Workspace::class)->assertSee('Paid repair');
        $actual = DB::table('finance_documents')->where('kind', 'actual')->value('id');
        $this->allocate($this->bank('500', 'BANK-2'), $actual, '500');
        $this->assertSame('closed', $this->requestRecord->fresh()->status);
        $this->assertTrue($this->finance->settled($this->requestRecord->id));
    }

    public function test_underuse_refund_requires_unused_allocation_reversal_and_confirmation(): void
    {
        $invoice = $this->approved();
        $receipt = $this->bank();
        $allocation = $this->allocate($receipt, $invoice, '500');
        $this->accept($this->readyResult(30));
        $this->assertSame(20000, $this->finance->balance($this->requestRecord->id)['credit']);
        try {
            $this->finance->approveRefund($this->staff, $receipt, '200', 'Unused scope', $this->key());
            $this->fail('Allocated funds refunded');
        } catch (ValidationException) {
        }
        $this->finance->reverseAllocation($this->staff, $allocation, '200', 'Unused scope', $this->key());
        $refund = $this->finance->approveRefund($this->staff, $receipt, '200', 'Unused scope', $this->key())['id'];
        $this->assertFalse($this->finance->settled($this->requestRecord->id));
        $this->assertFalse(DB::table('fiscal_receipt_records')->where('basis_type', 'refund')->exists());
        $key = $this->key();
        $first = $this->finance->confirmBankRefund($this->staff, $refund, 'RETURN-1', 'Verified statement', $key);
        $this->assertSame($first, $this->finance->confirmBankRefund($this->staff, $refund, 'RETURN-1', 'Verified statement', $key));
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'refund', 'basis_id' => $refund, 'scenario' => 'refund', 'amount_kopecks' => -20000]);
        $this->assertSame(1, DB::table('fiscal_receipt_records')->where('basis_type', 'refund')->count());
        $this->assertSame('closed', $this->requestRecord->fresh()->status);
        $this->assertDatabaseCount('refund_confirmations', 1);
        $this->assertTrue($this->finance->settled($this->requestRecord->id));
    }

    public function test_used_advance_cannot_be_reversed(): void
    {
        $invoice = $this->approved();
        $receipt = $this->bank();
        $allocation = $this->allocate($receipt, $invoice, '500');
        $this->accept($this->readyResult(30));
        $this->expectException(ValidationException::class);
        $this->finance->reverseAllocation($this->staff, $allocation, '200.01', 'Attempt reversal', $this->key());
    }

    public function test_billing_authorization_and_safe_acceptance_preview(): void
    {
        $invoice = $this->approved();
        $this->allocate($this->bank(), $invoice, '500');
        $this->readyResult(30);
        Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $this->requestRecord->id])->assertSee('Фактически выполнено')->assertSee('300.00')->assertSee('200.00');
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->assertSee('Test tax configuration')->assertDontSee('Test account')->assertDontSee('Зарегистрировать банковское поступление');
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->assertSee('Зарегистрировать банковское поступление');
        Livewire::actingAs($this->tech)->test(Billing::class, ['id' => $this->requestRecord->id])->assertForbidden();
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid financial action accepted');
        } catch (ValidationException) {
        }
    }

    private function materialLine(string $quantity = '2'): array
    {
        $warehouse = User::factory()->create(['kind' => 'staff', 'roles' => ['warehouse']]);
        $material = app(Inventory::class)->material($warehouse, ['code' => 'REV-M1', 'name' => 'Revision pipe', 'unit' => 'm', 'reason' => 'Catalog entry'], $this->key())['id'];

        return [...$this->line(quantity: $quantity), 'kind' => 'material', 'material_id' => $material];
    }

    public function test_pending_and_rejected_revision_preserve_funded_approved_scope(): void
    {
        $advance = $this->approved();
        $this->allocate($this->bank(), $advance, '500');
        $baseline = $this->finance->approvedEstimate($this->package);
        $revision = $this->estimate([$this->line(quantity: '150')]);
        $this->assertTrue($this->finance->executionReady($this->package));
        $this->assertSame($baseline->id, $this->finance->approvedEstimate($this->package)->id);
        $this->finance->decideEstimate($this->client, $revision, 'rejected', 'Keep original scope', $this->key());
        $this->assertTrue($this->finance->executionReady($this->package));
        $this->assertSame($baseline->id, $this->finance->approvedEstimate($this->package)->id);
        $this->assertDatabaseCount('finance_documents', 1);
    }

    public function test_started_paid_visit_can_save_and_stop_after_approved_increase_but_not_finish(): void
    {
        $advance = $this->approved();
        $this->allocate($this->bank(), $advance, '500');
        $w = app(Workflow::class);
        $tz = $this->requestRecord->site->timezone;
        $visit = $w->schedule($this->dispatcher, $this->package->id, $this->package->fresh()->version, ['technician_id' => $this->tech->id, 'start' => now($tz)->addHour()->format('Y-m-d\TH:i'), 'end' => now($tz)->addHours(2)->format('Y-m-d\TH:i')], $this->key())['id'];
        $w->visit($this->tech, $visit, 1, 'start', [], $this->key());
        $this->finance->decideEstimate($this->client, $this->estimate([$this->line(quantity: '150')]), 'approved', '', $this->key());
        $this->assertFalse($this->finance->executionReady($this->package));
        $report = ['report' => 'Partial repair', 'checks' => [true], 'labor_minutes' => 30, 'usage_confirmed' => true, 'usage' => []];
        $this->rejects(fn () => $w->visit($this->tech, $visit, 2, 'finish', $report, $this->key()));
        $draft = $w->visit($this->tech, $visit, 2, 'draft', $report, $this->key());
        $w->visit($this->tech, $visit, $draft['version'], 'interrupt', [...$report, 'reason' => 'Additional advance pending'], $this->key());
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'interrupted', 'report' => 'Partial repair', 'labor_minutes' => 30]);
    }

    public function test_approved_increase_invoices_only_shortfall_and_preserves_old_invoice(): void
    {
        $advance = $this->approved();
        $this->allocate($this->bank(), $advance, '500');
        $revision = $this->estimate([$this->line(quantity: '150')]);
        $key = $this->key();
        $result = $this->finance->decideEstimate($this->client, $revision, 'approved', '', $key);
        $this->assertSame($result, $this->finance->decideEstimate($this->client, $revision, 'approved', '', $key));
        $this->assertDatabaseHas('finance_documents', ['id' => $advance, 'total_kopecks' => 50000]);
        $this->assertDatabaseHas('finance_documents', ['id' => $result['invoice_id'], 'total_kopecks' => 25000]);
        $this->assertFalse($this->finance->executionReady($this->package));
        $this->allocate($this->bank('250', 'DELTA-1'), $result['invoice_id'], '250');
        $this->assertTrue($this->finance->executionReady($this->package));
        $this->assertDatabaseCount('finance_documents', 2);
    }

    public function test_approved_reduction_appends_credit_without_zero_invoice(): void
    {
        $advance = $this->approved();
        $allocation = $this->allocate($receipt = $this->bank(), $advance, '500');
        $revision = $this->estimate([$this->line(quantity: '80')]);
        $result = $this->finance->decideEstimate($this->client, $revision, 'approved', '', $this->key());
        $this->assertNull($result['invoice_id']);
        $this->assertDatabaseHas('finance_documents', ['id' => $advance, 'total_kopecks' => 50000]);
        $this->assertDatabaseHas('finance_documents', ['kind' => 'advance_credit', 'total_kopecks' => -10000]);
        $this->assertSame([$this->package->id => 40000], $this->finance->effectiveShares(DB::table('finance_documents')->find($advance)));
        $this->assertTrue($this->finance->executionReady($this->package));
        $this->finance->reverseAllocation($this->staff, $allocation, '100', 'Released excess', $this->key());
        $this->assertTrue($this->finance->executionReady($this->package));
        $this->rejects(fn () => $this->allocate($receipt, $advance, '0.01'));
        $this->assertDatabaseCount('finance_documents', 2);
    }

    public function test_material_revisions_adjust_only_incremental_demand(): void
    {
        $line = $this->materialLine();
        $first = $this->estimate([$this->line(), $line]);
        $this->finance->decideEstimate($this->client, $first, 'approved', '', $this->key());
        $second = $this->estimate([$this->line(), [...$line, 'quantity' => '3']]);
        $this->finance->decideEstimate($this->client, $second, 'approved', '', $this->key());
        $this->assertSame(3000, (int) DB::table('material_demands')->where('status', 'approved')->sum('quantity_milli'));
        $this->assertDatabaseCount('material_demands', 2);
        $third = $this->estimate([$this->line(), [...$line, 'quantity' => '1']]);
        $this->finance->decideEstimate($this->client, $third, 'approved', '', $this->key());
        $this->assertSame(1000, (int) DB::table('material_demands')->where('status', 'approved')->sum('quantity_milli'));
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_reserved_demand_reduction_rolls_back_decision_and_credit(): void
    {
        $line = $this->materialLine();
        $first = $this->estimate([$this->line(), $line]);
        $this->finance->decideEstimate($this->client, $first, 'approved', '', $this->key());
        $warehouse = User::factory()->create(['kind' => 'staff', 'roles' => ['warehouse']]);
        $inventory = app(Inventory::class);
        $store = $inventory->location($warehouse, 'Revision store', null, 'Opening store', $this->key())['id'];
        $inventory->opening($warehouse, $line['material_id'], $store, '2', '100', 'Opening stock', $this->key());
        $demand = DB::table('material_demands')->value('id');
        $inventory->reserve($warehouse, $demand, $store, '2', 'Held stock', $this->key());
        $revision = $this->estimate([$this->line(), [...$line, 'quantity' => '1']]);
        $this->rejects(fn () => $this->finance->decideEstimate($this->client, $revision, 'approved', '', $this->key()));
        $this->assertDatabaseMissing('estimate_decisions', ['estimate_id' => $revision]);
        $this->assertDatabaseCount('finance_corrections', 0);
        $this->assertDatabaseCount('finance_documents', 1);
        $this->assertDatabaseHas('material_demands', ['id' => $demand, 'quantity_milli' => 2000]);
        $this->assertTrue($inventory->reconcile());
    }

    public function test_completed_scope_cannot_be_repriced_or_reduced_below_used_quantity(): void
    {
        $advance = $this->approved();
        $this->allocate($this->bank(), $advance, '500');
        $this->readyResult(60);
        $this->rejects(fn () => $this->estimate([$this->line(quantity: '59')]));
        $this->rejects(fn () => $this->estimate([$this->line(quantity: '100', rate: '11')]));
        $this->assertDatabaseCount('estimates', 1);
        $revision = $this->estimate([$this->line(quantity: '60')]);
        $this->finance->decideEstimate($this->client, $revision, 'approved', '', $this->key());
        $this->assertSame(60000, $this->finance->actualPreview($this->package)['total_kopecks']);
    }

    public function test_accepted_consolidated_scope_is_preserved_while_other_package_changes(): void
    {
        $second = $this->package->replicate();
        $second->ordinal = 2;
        $second->title = 'Second repair';
        $second->save();
        $first = $this->estimate([$this->line(), $this->line($second)], 'consolidated_request');
        $advance = $this->finance->decideEstimate($this->client, $first, 'approved', '', $this->key())['invoice_id'];
        $this->allocate($this->bank(), $advance, '500');
        $this->accept($this->readyResult());
        $this->rejects(fn () => $this->estimate([$this->line(quantity: '99'), $this->line($second)], 'consolidated_request'));
        $this->rejects(fn () => $this->estimate([$this->line($second)], 'consolidated_request'));
        $revision = $this->estimate([$this->line(), $this->line($second, '150')], 'consolidated_request');
        $invoice = $this->finance->decideEstimate($this->client, $revision, 'approved', '', $this->key())['invoice_id'];
        $this->assertSame([$second->id => 25000], json_decode(DB::table('finance_documents')->find($invoice)->shares, true));
        $this->assertDatabaseHas('finance_documents', ['kind' => 'actual', 'work_package_id' => $this->package->id, 'total_kopecks' => 100000]);
        $this->assertSame('accepted', $this->package->fresh()->status);
        $this->assertSame(50000, (int) DB::table('advance_offsets')->sum('amount_kopecks'));
    }

    public function test_cancelled_consolidated_scope_is_credited_without_reallocation(): void
    {
        $second = $this->package->replicate();
        $second->ordinal = 2;
        $second->save();
        $first = $this->estimate([$this->line(), $this->line($second)], 'consolidated_request');
        $advance = $this->finance->decideEstimate($this->client, $first, 'approved', '', $this->key())['invoice_id'];
        $second->update(['status' => 'cancelled']);
        $revision = $this->estimate([$this->line()], 'consolidated_request');
        $this->finance->decideEstimate($this->client, $revision, 'approved', '', $this->key());
        $this->assertSame([$this->package->id => 50000, $second->id => 0], $this->finance->effectiveShares(DB::table('finance_documents')->find($advance)));
        $this->assertDatabaseHas('finance_documents', ['kind' => 'advance_credit', 'total_kopecks' => -50000]);
    }

    public function test_actual_credit_unwinds_direct_allocations_then_offsets_once(): void
    {
        $advance = $this->approved();
        $firstReceipt = $this->bank();
        $advanceAllocation = $this->allocate($firstReceipt, $advance, '500');
        $this->accept($this->readyResult());
        $actual = DB::table('finance_documents')->where('kind', 'actual')->value('id');
        $secondReceipt = $this->bank('500', 'CREDIT-2');
        $direct = $this->allocate($secondReceipt, $actual, '500');
        $key = $this->key();
        $result = $this->finance->creditActual($this->staff, $actual, '900', 'Verified correction', $key);
        $this->assertSame($result, $this->finance->creditActual($this->staff, $actual, '900', 'Verified correction', $key));
        $this->assertDatabaseHas('finance_documents', ['id' => $actual, 'total_kopecks' => 100000]);
        $this->assertDatabaseHas('finance_documents', ['id' => $result['id'], 'kind' => 'actual_credit', 'total_kopecks' => -90000]);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'credit', 'basis_id' => $result['id'], 'scenario' => 'correction', 'amount_kopecks' => -90000, 'actor_id' => $this->staff->id]);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'offset', 'scenario' => 'correction', 'amount_kopecks' => -40000, 'actor_id' => $this->staff->id]);
        $this->assertSame(1, DB::table('fiscal_receipt_records')->where('basis_type', 'credit')->count());
        $this->assertDatabaseHas('payment_allocations', ['reversal_of_id' => $direct, 'amount_kopecks' => -50000]);
        $this->assertSame(10000, (int) DB::table('advance_offsets')->sum('amount_kopecks'));
        $this->assertDatabaseCount('advance_offset_reversals', 1);
        $this->assertSame(90000, $this->finance->balance($this->requestRecord->id)['credit']);
        $this->assertSame('accepted', $this->package->fresh()->status);
        $this->assertSame('closed', $this->requestRecord->fresh()->status);
        $this->assertFalse($this->finance->settled($this->requestRecord->id));
        $this->finance->reverseAllocation($this->staff, $advanceAllocation, '400', 'Released corrected advance', $this->key());
        foreach ([$firstReceipt => '400', $secondReceipt => '500'] as $receipt => $amount) {
            $refund = $this->finance->approveRefund($this->staff, $receipt, $amount, 'Credit refund', $this->key())['id'];
            $this->finance->confirmBankRefund($this->staff, $refund, 'RETURN-'.$receipt, 'Verified statement', $this->key());
        }
        $this->assertTrue($this->finance->settled($this->requestRecord->id));
        $this->rejects(fn () => $this->finance->creditActual($this->staff, $actual, '100.01', 'Excess credit', $this->key()));
    }

    public function test_repeated_credits_do_not_reverse_an_offset_twice(): void
    {
        $advance = $this->approved();
        $this->allocate($this->bank(), $advance, '500');
        $this->accept($this->readyResult(50));
        $actual = DB::table('finance_documents')->where('kind', 'actual')->value('id');
        $this->finance->creditActual($this->staff, $actual, '200', 'First correction', $this->key());
        $this->finance->creditActual($this->staff, $actual, '300', 'Remaining correction', $this->key());
        $this->assertSame(0, (int) DB::table('advance_offsets')->sum('amount_kopecks'));
        $this->assertSame(0, $this->finance->balance($this->requestRecord->id)['charged']);
        $this->assertDatabaseCount('advance_offset_reversals', 2);
        $this->rejects(fn () => $this->finance->creditActual($this->staff, $actual, '0.01', 'Excess correction', $this->key()));
    }

    public function test_bank_reversal_requires_cleared_receipt_and_replay_preserves_original(): void
    {
        $advance = $this->approved();
        $receipt = $this->bank();
        $allocation = $this->allocate($receipt, $advance, '500');
        $this->rejects(fn () => $this->finance->reverseBankReceipt($this->staff, $receipt, 'Incorrect statement', 'Verified replacement', $this->key()));
        $this->finance->reverseAllocation($this->staff, $allocation, '500', 'Incorrect statement', $this->key());
        $key = $this->key();
        $result = $this->finance->reverseBankReceipt($this->staff, $receipt, 'Incorrect statement', 'Verified replacement', $key);
        $this->assertSame($result, $this->finance->reverseBankReceipt($this->staff, $receipt, 'Incorrect statement', 'Verified replacement', $key));
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'bank_reversal', 'basis_id' => $result['id'], 'scenario' => 'correction', 'amount_kopecks' => -50000]);
        $this->assertDatabaseCount('fiscal_receipt_records', 2);
        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt, 'amount_kopecks' => 50000]);
        $this->assertDatabaseCount('bank_receipt_reversals', 1);
        $this->assertSame(0, $this->finance->balance($this->requestRecord->id)['received']);
        $this->rejects(fn () => $this->allocate($receipt, $advance, '1'));
        $this->package->update(['status' => 'cancelled']);
        $this->rejects(fn () => $this->finance->approveRefund($this->staff, $receipt, '1', 'Reversed receipt', $this->key()));
        $this->rejects(fn () => $this->finance->reverseBankReceipt($this->staff, $receipt, 'Duplicate reversal', 'Verified replacement', $this->key()));
    }

    public function test_receipt_with_pending_refund_cannot_be_reversed(): void
    {
        $receipt = $this->bank();
        $this->package->update(['status' => 'cancelled']);
        $this->finance->approveRefund($this->staff, $receipt, '500', 'Cancelled scope', $this->key());
        $this->rejects(fn () => $this->finance->reverseBankReceipt($this->staff, $receipt, 'Incorrect statement', 'Verified replacement', $this->key()));
        $this->assertDatabaseCount('bank_receipt_reversals', 0);
    }

    public function test_correction_evidence_is_database_immutable(): void
    {
        $receipt = $this->bank();
        $this->finance->reverseBankReceipt($this->staff, $receipt, 'Incorrect statement', 'Verified replacement', $this->key());
        $this->expectException(QueryException::class);
        DB::table('bank_receipt_reversals')->update(['amount_kopecks' => 1]);
    }

    public function test_billing_revision_and_correction_actions_enforce_finance_role(): void
    {
        $advance = $this->approved();
        $estimate = DB::table('finance_documents')->find($advance)->estimate_id;
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->call('loadRevision', $estimate)->assertSet('lines.0.quantity', '100.000')->assertHasNoErrors();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->call('loadRevision', $estimate)->assertForbidden();
        $receipt = $this->bank();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->set('receipt', $receipt)->set('reason', 'Unauthorized correction')->set('evidence', 'Statement')->call('reverseBankReceipt')->assertForbidden();
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->set('receipt', $receipt)->set('reason', 'Verified correction')->set('evidence', 'Statement')->call('reverseBankReceipt')->assertHasNoErrors()->assertSee('сторнировано отдельной записью');
    }

    public function test_browser_fixtures_cannot_seed_the_application_or_test_database(): void
    {
        $before = DB::table('users')->count();
        try {
            $this->seed(BrowserVerificationSeeder::class);
            $this->fail('Unsafe browser fixture database accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Browser fixtures require the isolated verification database.', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('users')->count());
    }

    public function test_catalog_rate_copies_only_selected_draft_line_and_preserves_issued_snapshot(): void
    {
        $warehouse = User::factory()->create(['kind' => 'staff', 'roles' => ['warehouse']]);
        $stock = app(Inventory::class);
        $catalog = ['code' => 'CATALOG', 'name' => 'Original material', 'unit' => 'piece', 'reason' => 'Catalog entry', 'default_rate' => '123.45'];
        $material = $stock->material($warehouse, $catalog, $this->key())['id'];
        $line = [...$this->line(quantity: '2', rate: '9'), 'kind' => 'material', 'material_id' => $material, 'description' => 'Client material description'];
        $page = Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->set('lines', [$line, $this->line()])
            ->assertSee('Взять типовую цену из каталога')->call('useCatalogRate', 0)->assertHasNoErrors()->assertSet('lines.0.rate', '123.45')->assertSet('lines.1.rate', '10');
        $page->set('reason', 'Verified catalog quote')->call('issue')->assertHasNoErrors();
        $estimate = DB::table('estimates')->first();
        $this->assertSame(12345, json_decode($estimate->lines, true)[0]['rate_kopecks']);
        $this->rejects(fn () => $stock->updateMaterial($warehouse, $material, 1, [...$catalog, 'unit' => 'box'], $this->key()));
        $this->rejects(fn () => $stock->updateMaterial($warehouse, $material, 1, [...$catalog, 'active' => false], $this->key()));
        $stock->updateMaterial($warehouse, $material, 1, [...$catalog, 'name' => 'Renamed material', 'default_rate' => '234.56'], $this->key());
        $page->call('useCatalogRate', 0)->assertSet('lines.0.rate', '234.56')->assertSet('lines.1.rate', '10');
        $this->assertSame($estimate->lines, DB::table('estimates')->where('id', $estimate->id)->value('lines'));
        $this->assertDatabaseCount('finance_documents', 0);
    }

    public function test_catalog_rate_requires_active_priced_material_and_never_overwrites_on_error(): void
    {
        $line = $this->materialLine();
        $page = Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->set('lines', [$line])
            ->assertDontSee('Взять типовую цену из каталога')->call('useCatalogRate', 0)->assertHasErrors('lines.0.rate')->assertSet('lines.0.rate', $line['rate']);
        DB::table('materials')->where('id', $line['material_id'])->update(['default_rate_kopecks' => 0]);
        $page->call('useCatalogRate', 0)->assertHasNoErrors()->assertSet('lines.0.rate', '0.00');
        DB::table('materials')->where('id', $line['material_id'])->update(['active' => false]);
        $page->call('useCatalogRate', 0)->assertHasErrors('lines.0.rate')->assertSet('lines.0.rate', '0.00');
        $this->assertDatabaseCount('estimates', 0);
    }

    public function test_catalog_rate_action_checks_finance_authority_again_on_every_call(): void
    {
        $line = $this->materialLine();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->set('lines', [$line])->call('useCatalogRate', 0)->assertForbidden();
        $page = Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->set('lines', [$line]);
        $this->staff->forceFill(['roles' => []])->save();
        $this->assertFalse($this->staff->fresh()->hasRole('finance'));
        $page->call('useCatalogRate', 0)->assertForbidden();
        $this->assertDatabaseCount('estimates', 0);
    }

    public function test_fractional_credit_is_rendered_as_signed_money(): void
    {
        $this->approved();
        $revision = $this->estimate([$this->line(quantity: '1', rate: '800.02')]);
        $this->finance->decideEstimate($this->client, $revision, 'approved', '', $this->key());
        $this->assertDatabaseHas('finance_documents', ['kind' => 'advance_credit', 'total_kopecks' => -9999]);
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->assertSee('-99.99 ₽')->assertDontSee('-99.-99');
    }

    private function databaseRejects(callable $action, string $message = 'Invalid finance'): void
    {
        try {
            DB::transaction($action);
            $this->fail('Invalid financial posting accepted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function postingRows(): array
    {
        $advance = $this->approved();
        $receipt = $this->bank('600');
        $allocation = $this->allocate($receipt, $advance, '500');
        $this->accept($this->readyResult(50));
        $actual = DB::table('finance_documents')->where('kind', 'actual')->value('id');
        $credit = $this->finance->creditActual($this->staff, $actual, '100', 'Verified correction', $this->key())['id'];
        $refund = $this->finance->approveRefund($this->staff, $receipt, '100', 'Unused balance', $this->key())['id'];

        return [
            'estimates' => (array) DB::table('estimates')->first(),
            'finance_documents' => (array) DB::table('finance_documents')->find($advance),
            'payment_receipts' => (array) DB::table('payment_receipts')->find($receipt),
            'payment_allocations' => (array) DB::table('payment_allocations')->find($allocation),
            'advance_offsets' => (array) DB::table('advance_offsets')->where('amount_kopecks', '>', 0)->first(),
            'refunds' => (array) DB::table('refunds')->find($refund),
            'finance_corrections' => (array) DB::table('finance_corrections')->where('credit_document_id', $credit)->first(),
            'advance_offset_reversals' => (array) DB::table('advance_offset_reversals')->first(),
        ];
    }

    private function newPosting(array $row): array
    {
        unset($row['id']);
        foreach (['number', 'basis', 'receipt_key', 'group_key'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = 'test:'.$this->key();
            }
        }

        return $row;
    }

    public function test_database_rejects_invalid_financial_amounts_and_reversal_signs(): void
    {
        $rows = $this->postingRows();
        $cases = [
            'estimates' => [['total_kopecks' => 0], ['total_kopecks' => -1], ['total_kopecks' => 1.5], ['revision' => 0], ['revision' => 1.5]],
            'finance_documents' => [['total_kopecks' => 0], ['total_kopecks' => -1], ['total_kopecks' => 1.5], ['kind' => 'advance_credit', 'total_kopecks' => 1]],
            'payment_receipts' => [['amount_kopecks' => 0], ['amount_kopecks' => -1], ['amount_kopecks' => 1.5], ['amount_kopecks' => 'invalid']],
            'payment_allocations' => [['amount_kopecks' => 0], ['amount_kopecks' => -1], ['amount_kopecks' => 1.5], ['reversal_of_id' => $rows['payment_allocations']['id'], 'amount_kopecks' => 1]],
            'advance_offsets' => [['amount_kopecks' => 0], ['amount_kopecks' => 1.5]],
            'refunds' => [['amount_kopecks' => 0], ['amount_kopecks' => -1], ['amount_kopecks' => 1.5]],
        ];
        foreach ($cases as $table => $changesets) {
            $count = DB::table($table)->count();
            foreach ($changesets as $changes) {
                $insert = [...$this->newPosting($rows[$table]), ...$changes];
                $this->databaseRejects(fn () => DB::table($table)->insert($insert));
            }
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(0, (int) DB::table('payment_allocations')->whereNotNull('reversal_of_id')->sum('amount_kopecks'));
        $this->assertSame(40000, (int) DB::table('advance_offsets')->sum('amount_kopecks'));
    }

    public function test_database_rejects_cross_request_documents_receipts_and_allocations(): void
    {
        $rows = $this->postingRows();
        $other = $this->requestRecord->replicate();
        $other->number = 'OTHER-FINANCE';
        $other->save();
        $foreignPackage = $this->package->replicate();
        $foreignPackage->service_request_id = $other->id;
        $foreignPackage->save();
        $otherOrg = DB::table('organizations')->insertGetId(['name' => 'Foreign payer']);
        $cases = [
            ['finance_documents', ['service_request_id' => $other->id]],
            ['finance_documents', ['kind' => 'actual', 'work_package_id' => $foreignPackage->id]],
            ['finance_documents', ['kind' => 'actual', 'work_package_id' => null]],
            ['payment_receipts', ['organization_id' => $otherOrg]],
            ['payment_allocations', ['work_package_id' => $foreignPackage->id]],
            ['payment_allocations', ['reversal_of_id' => $rows['payment_allocations']['id'], 'amount_kopecks' => -50001]],
            ['advance_offsets', ['work_package_id' => $foreignPackage->id]],
            ['advance_offsets', ['actual_document_id' => $rows['finance_documents']['id']]],
        ];
        foreach ($cases as [$table, $changes]) {
            $insert = [...$this->newPosting($rows[$table]), ...$changes];
            $this->databaseRejects(fn () => DB::table($table)->insert($insert), 'Invalid finance basis');
        }
        $foreignReceipt = $this->finance->recordBank($this->staff, $other->id, $this->bankInput('10', 'FOREIGN-BANK'), $this->key())['id'];
        $insert = [...$this->newPosting($rows['payment_allocations']), 'payment_receipt_id' => $foreignReceipt];
        $this->databaseRejects(fn () => DB::table('payment_allocations')->insert($insert), 'Invalid finance basis');
        $sameRequestReceipt = $this->bank('10', 'SECOND-BANK');
        $insert = [...$this->newPosting($rows['payment_allocations']), 'payment_receipt_id' => $sameRequestReceipt, 'reversal_of_id' => $rows['payment_allocations']['id'], 'amount_kopecks' => -1];
        $this->databaseRejects(fn () => DB::table('payment_allocations')->insert($insert), 'Invalid finance basis');
    }

    public function test_database_corrections_require_matching_original_postings(): void
    {
        $rows = $this->postingRows();
        $correction = $this->newPosting($rows['finance_corrections']);
        $this->databaseRejects(fn () => DB::table('finance_corrections')->insert([...$correction, 'original_document_id' => $rows['finance_documents']['id']]), 'Invalid finance basis');
        $reversal = $this->newPosting($rows['advance_offset_reversals']);
        $this->databaseRejects(fn () => DB::table('advance_offset_reversals')->insert([...$reversal, 'reversal_offset_id' => $rows['advance_offsets']['id']]), 'Invalid finance basis');
        $this->databaseRejects(fn () => DB::table('advance_offset_reversals')->insert([...$reversal, 'original_offset_id' => $reversal['reversal_offset_id']]), 'Invalid finance basis');
        $receipt = $this->bank('10', 'REVERSAL-BASIS');
        $bankReversal = ['payment_receipt_id' => $receipt, 'actor_id' => $this->staff->id, 'amount_kopecks' => 999, 'reason' => 'Correction', 'evidence' => 'Verified statement'];
        $this->databaseRejects(fn () => DB::table('bank_receipt_reversals')->insert($bankReversal), 'Invalid finance basis');
        $result = $this->finance->reverseBankReceipt($this->staff, $receipt, 'Correction', 'Verified statement', $this->key());
        $this->assertDatabaseHas('bank_receipt_reversals', ['id' => $result['id'], 'amount_kopecks' => 1000]);
        $this->assertDatabaseCount('finance_corrections', 1);
        $this->assertDatabaseCount('advance_offset_reversals', 1);
    }

    public function test_refund_basis_is_immutable_while_status_can_progress(): void
    {
        $rows = $this->postingRows();
        foreach (['payment_receipt_id' => $this->bank('10', 'OTHER-REFUND'), 'actor_id' => $this->dispatcher->id, 'amount_kopecks' => 1, 'reason' => 'Changed basis', 'created_at' => now()->subDay()->toDateTimeString()] as $field => $value) {
            $this->databaseRejects(fn () => DB::table('refunds')->where('id', $rows['refunds']['id'])->update([$field => $value]), 'Refund basis is immutable');
        }
        $this->finance->confirmBankRefund($this->staff, $rows['refunds']['id'], 'REFUND-COMPLETE', 'Verified bank statement', $this->key());
        $this->assertDatabaseHas('refunds', ['id' => $rows['refunds']['id'], 'status' => 'succeeded', 'amount_kopecks' => 10000]);
        $this->assertDatabaseCount('refund_confirmations', 1);
    }

    public function test_zero_actual_invoice_is_valid_and_does_not_consume_advance(): void
    {
        $advance = $this->approved();
        $this->allocate($this->bank(), $advance, '500');
        $this->accept($this->readyResult(0));
        $this->assertDatabaseHas('finance_documents', ['kind' => 'actual', 'total_kopecks' => 0, 'work_package_id' => $this->package->id]);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'actual', 'scenario' => 'final_settlement', 'amount_kopecks' => 0]);
        $this->assertDatabaseCount('advance_offsets', 0);
        $this->assertSame(50000, $this->finance->balance($this->requestRecord->id)['credit']);
        $this->assertFalse($this->finance->settled($this->requestRecord->id));
    }

    public function test_finance_migration_round_trip_preserves_posted_immutability(): void
    {
        $rows = $this->postingRows();
        $migration = require database_path('migrations/2026_11_12_073404_add_finance_integrity_constraints.php');
        $migration->down();
        $this->databaseRejects(fn () => DB::table('payment_receipts')->update(['amount_kopecks' => 1]), 'Posted finance is immutable');
        $migration->up();
        $this->assertSame(16, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', '%_finance_%')->count());
        $insert = [...$this->newPosting($rows['payment_receipts']), 'amount_kopecks' => 0];
        $this->databaseRejects(fn () => DB::table('payment_receipts')->insert($insert), 'Invalid finance amount');
    }

    public function test_finance_migration_refuses_invalid_existing_amounts_and_ownership(): void
    {
        $rows = $this->postingRows();
        $migration = require database_path('migrations/2026_11_12_073404_add_finance_integrity_constraints.php');
        foreach (['amount', 'ownership'] as $invalid) {
            $migration->down();
            DB::beginTransaction();
            try {
                if ($invalid === 'amount') {
                    DB::table('refunds')->where('id', $rows['refunds']['id'])->update(['amount_kopecks' => -1]);
                    $expected = 'Resolve invalid finance amounts in refunds before migrating.';
                } else {
                    $org = DB::table('organizations')->insertGetId(['name' => 'Unrelated buyer']);
                    DB::table('payment_receipts')->insert([...$this->newPosting($rows['payment_receipts']), 'organization_id' => $org]);
                    $expected = 'Resolve invalid finance ownership in payment_receipts before migrating.';
                }
                try {
                    $migration->up();
                    $this->fail('Invalid existing financial posting accepted');
                } catch (\RuntimeException $exception) {
                    $this->assertSame($expected, $exception->getMessage());
                }
                $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', '%_finance_%')->count());
            } finally {
                DB::rollBack();
                $migration->up();
            }
        }
        $this->assertSame(40000, (int) DB::table('advance_offsets')->sum('amount_kopecks'));
    }

    public function test_posted_finance_history_is_immutable(): void
    {
        $this->estimate();
        $this->expectException(QueryException::class);
        DB::table('estimates')->update(['total_kopecks' => 1]);
    }
}
