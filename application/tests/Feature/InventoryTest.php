<?php

namespace Tests\Feature;

use App\Actions\Identity\ManageAccounts;
use App\Actions\Inventory\Inventory;
use App\Actions\Inventory\Purchasing;
use App\Actions\ServiceRequests\Workflow;
use App\Livewire\Job;
use App\Livewire\RequestDetail;
use App\Livewire\Stock;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPackage;
use App\Support\Fixed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Inventory $stock;

    private User $warehouse;

    private User $dispatcher;

    private User $tech;

    private WorkPackage $package;

    private Visit $visit;

    private int $material;

    private int $store;

    private int $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->stock = app(Inventory::class);
        $this->warehouse = User::factory()->create(['kind' => 'staff', 'roles' => ['warehouse']]);
        $this->dispatcher = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher']]);
        $this->tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $org = DB::table('organizations')->insertGetId(['name' => 'Test company']);
        $site = Site::create(['organization_id' => $org, 'name' => 'Test site', 'address' => 'Test address']);
        $r = ServiceRequest::create(['site_id' => $site->id, 'organization_id' => $org, 'author_id' => $this->dispatcher->id, 'number' => 'STOCK-TEST', 'title' => 'Test repair', 'description' => 'Test']);
        $contract = Contract::create(['organization_id' => $org, 'number' => 'INV-CONTRACT', 'title' => 'Coverage', 'starts_on' => now()->subYear(), 'ends_on' => now()->addYear(), 'covered_categories' => ['plumbing'], 'terms' => []]);
        $this->package = WorkPackage::create(['service_request_id' => $r->id, 'ordinal' => 1, 'contract_id' => $contract->id, 'contract_snapshot' => [], 'title' => 'Repair', 'scope' => 'Repair', 'category' => 'plumbing', 'kind' => 'covered', 'status' => 'scheduled', 'lead_id' => $this->tech->id, 'checklist_template' => [], 'photo_required' => false]);
        $this->visit = Visit::create(['work_package_id' => $this->package->id, 'technician_id' => $this->tech->id, 'planned_start' => now(), 'planned_end' => now()->addHour(), 'checklist' => [['text' => 'Safety', 'required' => true, 'done' => false]]]);
        $this->material = $this->stock->material($this->warehouse, ['code' => 'P1', 'name' => 'Test pipe', 'unit' => 'm', 'reason' => 'Catalog entry'], $this->key())['id'];
        $this->store = $this->stock->location($this->warehouse, 'Store', null, 'Opening store', $this->key())['id'];
        $this->bag = $this->stock->location($this->warehouse, 'Technician bag', $this->tech->id, 'Assign custody', $this->key())['id'];
        $this->stock->opening($this->warehouse, $this->material, $this->store, '10', '100', 'Initial stock', $this->key());
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function allocation(): int
    {
        $d = $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '3', 'Approved work', $this->key())['id'];
        $r = $this->stock->reserve($this->warehouse, $d, $this->store, '3', 'Reserve', $this->key())['id'];

        return $this->stock->issue($this->warehouse, $r, $this->bag, '3', 'Issue to worker', $this->key())['id'];
    }

    private function report(int $allocation, string $quantity = '1.250'): array
    {
        return ['report' => 'Repaired pipe', 'checks' => [true], 'labor_minutes' => 30, 'usage_confirmed' => true, 'usage' => [$allocation => $quantity]];
    }

    public function test_missing_issued_materials_block_start_but_a_reservation_does_not_count_as_ready(): void
    {
        $d = $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '3', 'Approved work', $this->key())['id'];
        $this->stock->reserve($this->warehouse, $d, $this->store, '3', 'Reserve only', $this->key());
        $this->assertFalse($this->stock->readiness($this->package)['ready']);
        Livewire::actingAs($this->tech)->test(Job::class, ['id' => $this->visit->id])->assertViewHas('canStart', false)->call('commitVisit', 'start')->assertHasErrors('stock');
        $this->assertDatabaseHas('visits', ['id' => $this->visit->id, 'status' => 'scheduled', 'version' => 1]);
    }

    public function test_partial_work_approval_allows_start_but_not_full_completion(): void
    {
        $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '3', 'Approved work', $this->key());
        $w = app(Workflow::class);
        $key = $this->key();
        $result = $w->allowPartialWork($this->dispatcher, $this->visit->id, 1, 'Inspect safely; no repair until parts arrive', $key);
        $this->assertSame($result, $w->allowPartialWork($this->dispatcher, $this->visit->id, 1, 'Inspect safely; no repair until parts arrive', $key));
        $w->visit($this->tech, $this->visit->id, 2, 'start', [], $this->key());
        $report = ['report' => 'Inspected only', 'checks' => [true], 'labor_minutes' => 15, 'usage_confirmed' => true, 'reason' => 'Waiting for parts'];
        try {
            $w->visit($this->tech, $this->visit->id, 3, 'finish_submit', $report, $this->key());
            $this->fail('Partial work submitted as complete');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('result_snapshots', 0);
        $w->visit($this->tech, $this->visit->id, 3, 'interrupt', $report, $this->key());
        $this->assertDatabaseHas('visits', ['id' => $this->visit->id, 'status' => 'interrupted', 'labor_minutes' => 15]);
    }

    public function test_material_changes_invalidate_partial_work_permission(): void
    {
        $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '3', 'Approved work', $this->key());
        $w = app(Workflow::class);
        $w->allowPartialWork($this->dispatcher, $this->visit->id, 1, 'Inspect only', $this->key());
        $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '1', 'Additional scope', $this->key());
        $this->expectException(ValidationException::class);
        $w->visit($this->tech, $this->visit->id, 2, 'start', [], $this->key());
    }

    public function test_fixed_point_rounding_and_quantity_validation(): void
    {
        $this->assertSame(1234, Fixed::quantity('1,234'));
        $this->assertSame(101, Fixed::ratio(201, 1, 2));
        $this->assertSame(12345, Fixed::money('123.45'));
        $this->expectException(ValidationException::class);
        Fixed::quantity('0.0001');
    }

    public function test_draft_has_no_stock_effect_and_finish_retry_posts_once(): void
    {
        $a = $this->allocation();
        $w = app(Workflow::class);
        $w->visit($this->tech, $this->visit->id, 1, 'start', [], $this->key());
        $w->visit($this->tech, $this->visit->id, 2, 'draft', $this->report($a), $this->key());
        $this->assertSame(3000, DB::table('stock_allocations')->where('id', $a)->value('remaining_milli'));
        $this->assertSame(0, DB::table('stock_documents')->where('kind', 'consume')->count());
        $key = $this->key();
        $first = $w->visit($this->tech, $this->visit->id, 3, 'finish_submit', $this->report($a), $key);
        $this->assertSame($first, $w->visit($this->tech, $this->visit->id, 3, 'finish_submit', $this->report($a), $key));
        $this->assertSame(1750, DB::table('stock_allocations')->where('id', $a)->value('remaining_milli'));
        $this->assertSame(1, DB::table('stock_documents')->where('kind', 'consume')->count());
        $evidence = $this->package->results()->first()->contributions[0]['materials'][0];
        $this->assertSame('1.250', $evidence['quantity']);
        $this->assertArrayNotHasKey('value_micro', $evidence);
        $this->stock->returnUnused($this->warehouse, $a, $this->store, '1.750', 'Unused stock', $this->key());
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_excess_usage_rolls_back_report_and_all_postings(): void
    {
        $a = $this->allocation();
        $w = app(Workflow::class);
        $w->visit($this->tech, $this->visit->id, 1, 'start', [], $this->key());
        try {
            $w->visit($this->tech, $this->visit->id, 2, 'finish', $this->report($a, '3.001'), $this->key());
            $this->fail('Excess accepted');
        } catch (ValidationException) {
        }
        $this->assertSame('in_progress', $this->visit->fresh()->status);
        $this->assertSame(2, $this->visit->fresh()->version);
        $this->assertSame(0, DB::table('stock_documents')->where('kind', 'consume')->count());
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_foreign_allocation_is_rejected_even_at_zero(): void
    {
        $a = $this->allocation();
        $other = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $v = Visit::create(['work_package_id' => $this->package->id, 'technician_id' => $other->id, 'planned_start' => now(), 'planned_end' => now()->addHour(), 'checklist' => [['text' => 'Safety', 'required' => true, 'done' => false]], 'status' => 'in_progress']);
        $this->expectException(ValidationException::class);
        app(Workflow::class)->visit($other, $v->id, 1, 'finish', $this->report($a, '0'), $this->key());
    }

    public function test_partial_receipts_keep_weighted_cost_and_retry_once(): void
    {
        $p = app(Purchasing::class);
        $supplier = $p->supplier($this->warehouse, 'Supplier', 'Legal details', $this->key())['id'];
        $order = $p->order($this->warehouse, ['supplier_id' => $supplier, 'location_id' => $this->store, 'expected_on' => now()->format('Y-m-d'), 'reason' => 'Restock', 'lines' => [['material_id' => $this->material, 'quantity' => '2', 'unit_cost' => '200']]], $this->key())['id'];
        $line = DB::table('purchase_lines')->where('purchase_order_id', $order)->value('id');
        $key = $this->key();
        $first = $p->receive($this->warehouse, $line, 1, '1', 'Delivered', $key);
        $this->assertSame($first, $p->receive($this->warehouse, $line, 1, '1', 'Delivered', $key));
        $this->assertSame('partially_received', DB::table('purchase_orders')->where('id', $order)->value('status'));
        $p->receive($this->warehouse, $line, 2, '1', 'Delivered remainder', $this->key());
        $this->assertSame('received', DB::table('purchase_orders')->where('id', $order)->value('status'));
        $position = DB::table('stock_positions')->where('stock_location_id', $this->store)->first();
        $this->assertSame(12000, $position->quantity_milli);
        $this->assertSame(140000000000, $position->value_micro);
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_reservation_cannot_exceed_free_stock(): void
    {
        $d = $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '11', 'Demand', $this->key())['id'];
        $this->expectException(ValidationException::class);
        $this->stock->reserve($this->warehouse, $d, $this->store, '11', 'Reserve', $this->key());
    }

    public function test_issued_stock_cannot_be_transferred_without_return(): void
    {
        $this->allocation();
        $this->expectException(ValidationException::class);
        $this->stock->transfer($this->warehouse, $this->material, $this->bag, $this->store, '1', 'Move', $this->key());
    }

    public function test_custody_guard_blocks_role_removal_then_reassignment_allows_it(): void
    {
        $admin = User::factory()->create(['kind' => 'staff', 'roles' => ['administrator']]);
        $this->stock->transfer($this->warehouse, $this->material, $this->store, $this->bag, '1', 'Free stock', $this->key());
        $this->visit->update(['status' => 'cancelled']);
        $this->package->update(['status' => 'cancelled']);
        $input = ['name' => $this->tech->name, 'email' => $this->tech->email, 'kind' => 'staff', 'roles' => ['warehouse'], 'permissions' => [], 'site_ids' => [], 'organization_id' => null, 'accept_work' => false, 'active' => false, 'reason' => 'Offboarding'];
        try {
            app(ManageAccounts::class)->update($admin, $this->tech->id, 1, $input, $this->key());
            $this->fail('Custody lost');
        } catch (ValidationException) {
        }
        $other = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $this->stock->reassignCustodian($this->warehouse, $this->bag, $other->id, 'Handover verified', $this->key());
        app(ManageAccounts::class)->update($admin, $this->tech->id, 1, $input, $this->key());
        $this->assertFalse($this->tech->fresh()->active);
        $this->assertDatabaseHas('stock_locations', ['id' => $this->bag, 'custodian_id' => $other->id]);
    }

    public function test_posted_stock_is_database_immutable(): void
    {
        $this->expectException(QueryException::class);
        DB::table('stock_entries')->update(['quantity_delta' => 0]);
    }

    public function test_worker_usage_starts_at_zero_without_internal_cost(): void
    {
        $a = $this->allocation();
        app(Workflow::class)->visit($this->tech, $this->visit->id, 1, 'start', [], $this->key());
        Livewire::actingAs($this->tech)->test(Job::class, ['id' => $this->visit->id])->assertSet('usage.'.$a, '0.000')->assertSee('Test pipe')->assertDontSee('value_micro');
    }

    public function test_job_uses_suggestion_for_labor_minutes(): void
    {
        $w = app(Workflow::class);
        $w->visit($this->tech, $this->visit->id, 1, 'start', [], $this->key());
        $this->visit->update(['actual_start' => now()->subMinutes(42)]);
        Livewire::actingAs($this->tech)->test(Job::class, ['id' => $this->visit->id])
            ->call('useSuggestion')
            ->assertSet('labor_minutes', 42);
    }

    public function test_interrupt_requires_details_for_other_reason(): void
    {
        app(Workflow::class)->visit($this->tech, $this->visit->id, 1, 'start', [], $this->key());
        Livewire::actingAs($this->tech)->test(Job::class, ['id' => $this->visit->id])
            ->set('helpReason', 'Другая причина')
            ->set('reason', '')
            ->set('labor_minutes', 10)
            ->set('usage_confirmed', true)
            ->call('commitVisit', 'interrupt')
            ->assertHasErrors('reason');
        $this->assertSame('in_progress', $this->visit->fresh()->status);
    }

    public function test_start_is_disabled_when_another_visit_is_in_progress(): void
    {
        $w = app(Workflow::class);
        $w->visit($this->tech, $this->visit->id, 1, 'start', [], $this->key());
        $otherPackage = $this->package->replicate();
        $otherPackage->ordinal = 2;
        $otherPackage->save();
        $otherVisit = Visit::create(['work_package_id' => $otherPackage->id, 'technician_id' => $this->tech->id, 'planned_start' => now()->addHour(), 'planned_end' => now()->addHours(2), 'checklist' => []]);
        Livewire::actingAs($this->tech)->test(Job::class, ['id' => $otherVisit->id])
            ->assertViewHas('canStart', false)
            ->assertSee('Сначала завершите или приостановите текущий выезд')
            ->assertSee($this->visit->package->title);
    }

    private function purchase(): array
    {
        $purchasing = app(Purchasing::class);
        $supplier = $purchasing->supplier($this->warehouse, 'Supplier', 'Legal details', $this->key())['id'];
        $order = $purchasing->order($this->warehouse, ['supplier_id' => $supplier, 'location_id' => $this->store, 'expected_on' => now()->format('Y-m-d'), 'reason' => 'Restock', 'lines' => [['material_id' => $this->material, 'quantity' => '2', 'unit_cost' => '200']]], $this->key())['id'];

        return [$order, DB::table('purchase_lines')->where('purchase_order_id', $order)->value('id')];
    }

    public function test_amendment_preserves_posted_receipt_cost_and_is_available_in_workbench(): void
    {
        [$order, $line] = $this->purchase();
        app(Purchasing::class)->receive($this->warehouse, $line, 1, '1', 'First delivery', $this->key());
        $value = DB::table('stock_entries')->where('stock_document_id', DB::table('stock_documents')->where('kind', 'receipt')->value('id'))->value('value_delta');
        Livewire::actingAs($this->warehouse)->test(Stock::class)->set('task', 'amend')->set('basis', $line)->set('version', 2)->set('quantity', '3')->set('cost', '300')->set('reason', 'Supplier change')->call('post')->assertHasNoErrors();
        app(Purchasing::class)->receive($this->warehouse, $line, 3, '2', 'Remaining delivery', $this->key());
        $this->assertSame(20000000000, $value);
        $this->assertSame(180000000000, DB::table('stock_positions')->where('stock_location_id', $this->store)->value('value_micro'));
        $this->assertDatabaseHas('purchase_orders', ['id' => $order, 'status' => 'received', 'version' => 4]);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase.amended']);
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_amendment_rejects_stale_version_and_quantity_below_received(): void
    {
        [$order, $line] = $this->purchase();
        app(Purchasing::class)->receive($this->warehouse, $line, 1, '1', 'First delivery', $this->key());
        foreach ([[1, '2'], [2, '0.999']] as [$version, $quantity]) {
            try {
                app(Purchasing::class)->amend($this->warehouse, $line, $version, $quantity, '300', now()->format('Y-m-d'), 'Change', $this->key());
                $this->fail('Invalid amendment accepted');
            } catch (ValidationException) {
            }
        }
        $key = $this->key();
        $first = app(Purchasing::class)->amend($this->warehouse, $line, 2, '1', '200', now()->format('Y-m-d'), 'Reduce undelivered scope', $key);
        $this->assertSame($first, app(Purchasing::class)->amend($this->warehouse, $line, 2, '1', '200', now()->format('Y-m-d'), 'Reduce undelivered scope', $key));
        $this->assertDatabaseHas('purchase_orders', ['id' => $order, 'status' => 'received', 'version' => 3]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'purchase.amended')->count());
    }

    public function test_receipt_reversal_preserves_cancelled_remainder_and_posts_once(): void
    {
        [$order, $line] = $this->purchase();
        $p = app(Purchasing::class);
        $receipt = $p->receive($this->warehouse, $line, 1, '1', 'Delivery', $this->key())['id'];
        $p->cancel($this->warehouse, $order, 2, 'Cancel remainder', $this->key());
        $key = $this->key();
        $first = $this->stock->reverse($this->warehouse, $receipt, 'Receipt correction', $key);
        $this->assertSame($first, $this->stock->reverse($this->warehouse, $receipt, 'Receipt correction', $key));
        $this->assertDatabaseHas('purchase_orders', ['id' => $order, 'status' => 'cancelled', 'version' => 4]);
        $this->assertDatabaseHas('purchase_lines', ['id' => $line, 'received_milli' => 0]);
        $this->assertSame(10000, DB::table('stock_positions')->where('stock_location_id', $this->store)->value('quantity_milli'));
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_rebuild_repairs_missing_and_orphaned_cache_without_posting_movements(): void
    {
        DB::table('stock_positions')->where('stock_location_id', $this->store)->delete();
        DB::table('stock_positions')->insert(['stock_location_id' => $this->bag, 'material_id' => $this->material, 'quantity_milli' => 1, 'value_micro' => 1]);
        $this->assertFalse($this->stock->reconcile());
        $count = DB::table('stock_entries')->count();
        Livewire::actingAs($this->warehouse)->test(Stock::class)->set('task', 'rebuild')->set('reason', 'Recover cache')->call('post')->assertHasNoErrors();
        $this->assertTrue($this->stock->reconcile());
        $this->assertSame($count, DB::table('stock_entries')->count());
        $this->assertDatabaseHas('stock_positions', ['stock_location_id' => $this->bag, 'quantity_milli' => 0, 'value_micro' => 0]);
    }

    public function test_shortages_exclude_covered_reservations_and_unreceived_purchases(): void
    {
        $demand = $this->stock->demand($this->dispatcher, $this->package->id, $this->material, '12', 'Approved need', $this->key())['id'];
        $this->stock->reserve($this->warehouse, $demand, $this->store, '3', 'Reserve', $this->key());
        $this->purchase();
        $row = $this->stock->shortages($this->warehouse)->first();
        $this->assertSame(9000, $row->required);
        $this->assertSame(7000, $row->free);
        $this->assertSame(2000, $row->shortage);
        $this->assertSame(2000, $row->ordered);
        $this->package->update(['status' => 'cancelled']);
        $this->assertCount(0, $this->stock->shortages($this->warehouse));
        Livewire::actingAs($this->tech)->test(Stock::class)->assertForbidden();
    }

    public function test_stock_and_purchase_sequences_are_independent(): void
    {
        [$order] = $this->purchase();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order, 'number' => 'PO-00000001']);
        $this->assertDatabaseHas('stock_documents', ['number' => 'STK-00000001']);
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid inventory action accepted');
        } catch (ValidationException) {
        }
    }

    private function catalogInput(int $id, array $changes = []): array
    {
        $row = DB::table('materials')->find($id);

        return [...['code' => $row->code, 'name' => $row->name, 'unit' => $row->unit, 'reason' => 'Verified catalog change'], ...$changes];
    }

    private function idleMaterial(string $code = 'IDLE'): int
    {
        return $this->stock->material($this->warehouse, ['code' => $code, 'name' => 'Idle material', 'unit' => 'piece', 'reason' => 'Catalog entry'], $this->key())['id'];
    }

    public function test_signed_fixed_point_display_preserves_fraction_and_integer_bounds(): void
    {
        foreach ([[-12345, 2, '-123.45'], [-1, 2, '-0.01'], [-1000, 3, '-1.000'], [0, 2, '0.00'], [PHP_INT_MAX, 2, '92233720368547758.07'], [PHP_INT_MIN, 2, '-92233720368547758.08'], [PHP_INT_MIN, 0, (string) PHP_INT_MIN]] as [$value, $scale, $expected]) {
            $this->assertSame($expected, Fixed::display($value, $scale));
        }
    }

    public function test_catalog_update_is_versioned_audited_and_retry_safe(): void
    {
        $id = $this->idleMaterial();
        $input = $this->catalogInput($id, ['default_rate' => '123,45', 'description' => 'Pressure rated', 'active' => true]);
        $key = $this->key();
        $result = $this->stock->updateMaterial($this->warehouse, $id, 1, $input, $key);
        $this->assertSame($result, $this->stock->updateMaterial($this->warehouse, $id, 1, $input, $key));
        $this->assertDatabaseHas('materials', ['id' => $id, 'version' => 2, 'default_rate_kopecks' => 12345, 'description' => 'Pressure rated']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'stock.material_updated')->count());
        $this->rejects(fn () => $this->stock->updateMaterial($this->warehouse, $id, 1, $input, $this->key()));
        $this->rejects(fn () => $this->stock->updateMaterial($this->warehouse, $id, 1, [...$input, 'name' => 'Changed retry'], $key));
        $this->stock->updateMaterial($this->warehouse, $id, 2, $this->catalogInput($id, ['unit' => 'box']), $this->key());
        $this->assertDatabaseHas('materials', ['id' => $id, 'version' => 3, 'unit' => 'box', 'default_rate_kopecks' => 12345]);
        $this->stock->updateMaterial($this->warehouse, $id, 3, $this->catalogInput($id, ['default_rate' => '']), $this->key());
        $this->assertNull(DB::table('materials')->where('id', $id)->value('default_rate_kopecks'));
        $this->assertSame(1, DB::table('stock_entries')->count());
    }

    public function test_catalog_rates_reject_invalid_precision_negative_and_overflow(): void
    {
        foreach (['-1', '1.001', '1000000000', '1e3'] as $rate) {
            $input = ['code' => 'INVALID', 'name' => 'Invalid', 'unit' => 'piece', 'reason' => 'Entry', 'default_rate' => $rate];
            $this->rejects(fn () => $this->stock->material($this->warehouse, $input, $this->key()));
        }
        $this->assertDatabaseCount('materials', 1);
        $id = $this->stock->material($this->warehouse, ['code' => 'ZERO', 'name' => 'Zero price', 'unit' => 'piece', 'reason' => 'Entry', 'default_rate' => '0'], $this->key())['id'];
        $this->assertDatabaseHas('materials', ['id' => $id, 'default_rate_kopecks' => 0]);
    }

    public function test_material_unit_cannot_change_after_stock_demand_or_purchase_history(): void
    {
        $demandMaterial = $this->idleMaterial('DEMAND');
        $this->stock->demand($this->dispatcher, $this->package->id, $demandMaterial, '1', 'Approved demand', $this->key());
        $purchaseMaterial = $this->idleMaterial('PURCHASE');
        $supplier = app(Purchasing::class)->supplier($this->warehouse, 'Supplier', 'Details', $this->key())['id'];
        app(Purchasing::class)->order($this->warehouse, ['supplier_id' => $supplier, 'location_id' => $this->store, 'expected_on' => now()->format('Y-m-d'), 'reason' => 'Order', 'lines' => [['material_id' => $purchaseMaterial, 'quantity' => '1', 'unit_cost' => '2']]], $this->key());
        foreach ([$this->material, $demandMaterial, $purchaseMaterial] as $id) {
            $this->rejects(fn () => $this->stock->updateMaterial($this->warehouse, $id, 1, $this->catalogInput($id, ['unit' => 'different']), $this->key()));
            $this->assertDatabaseHas('materials', ['id' => $id, 'version' => 1]);
        }
    }

    public function test_material_deactivation_checks_stock_demands_and_open_purchases(): void
    {
        foreach ([false, 0, '0'] as $active) {
            $this->rejects(fn () => $this->stock->updateMaterial($this->warehouse, $this->material, 1, $this->catalogInput($this->material, ['active' => $active]), $this->key()));
        }
        $demandMaterial = $this->idleMaterial('DEMAND');
        $this->stock->demand($this->dispatcher, $this->package->id, $demandMaterial, '1', 'Approved need', $this->key());
        $this->rejects(fn () => $this->stock->updateMaterial($this->warehouse, $demandMaterial, 1, $this->catalogInput($demandMaterial, ['active' => false]), $this->key()));
        $id = $this->idleMaterial();
        $purchasing = app(Purchasing::class);
        $supplier = $purchasing->supplier($this->warehouse, 'Supplier', 'Details', $this->key())['id'];
        $order = $purchasing->order($this->warehouse, ['supplier_id' => $supplier, 'location_id' => $this->store, 'expected_on' => now()->format('Y-m-d'), 'reason' => 'Order', 'lines' => [['material_id' => $id, 'quantity' => '1', 'unit_cost' => '2']]], $this->key())['id'];
        $this->rejects(fn () => $this->stock->updateMaterial($this->warehouse, $id, 1, $this->catalogInput($id, ['active' => false]), $this->key()));
        $purchasing->cancel($this->warehouse, $order, 1, 'No longer required', $this->key());
        $this->stock->updateMaterial($this->warehouse, $id, 1, $this->catalogInput($id, ['active' => false]), $this->key());
        $this->assertDatabaseHas('materials', ['id' => $id, 'active' => false, 'version' => 2]);
        $this->stock->updateMaterial($this->warehouse, $id, 2, $this->catalogInput($id, ['active' => true]), $this->key());
        $this->assertDatabaseHas('materials', ['id' => $id, 'active' => true, 'version' => 3]);
    }

    public function test_catalog_database_rejects_invalid_values(): void
    {
        foreach ([['default_rate_kopecks' => -1], ['default_rate_kopecks' => 100000000000], ['default_rate_kopecks' => 1.5], ['version' => 0]] as $changes) {
            try {
                DB::transaction(fn () => DB::table('materials')->where('id', $this->material)->update($changes));
                $this->fail('Invalid catalog value persisted');
            } catch (QueryException) {
            }
        }
        $this->assertDatabaseHas('materials', ['id' => $this->material, 'version' => 1, 'default_rate_kopecks' => null]);
    }

    public function test_catalog_workbench_refreshes_version_and_checks_fresh_authority(): void
    {
        $page = Livewire::actingAs($this->warehouse)->test(Stock::class)->call('editMaterial', $this->material)
            ->assertSet('task', 'material-update')->assertSet('name', 'Test pipe')->set('sellingRate', '234.56')->set('reason', 'Price verified')->call('post')->assertHasNoErrors()->assertSet('version', 2);
        $this->assertDatabaseHas('materials', ['id' => $this->material, 'default_rate_kopecks' => 23456]);
        $this->warehouse->forceFill(['roles' => []])->save();
        $this->assertFalse($this->warehouse->fresh()->hasRole('warehouse'));
        $page->call('editMaterial', $this->material)->assertForbidden();
    }

    public function test_supplier_contacts_are_optional_validated_and_deduplicated(): void
    {
        $purchasing = app(Purchasing::class);
        $contact = ['contact_name' => 'Test contact', 'contact_email' => 'supplier@example.test', 'contact_phone' => '+7 000 000-00-00'];
        $key = $this->key();
        $result = $purchasing->supplier($this->warehouse, 'Supplier', 'Details', $key, $contact);
        $this->assertSame($result, $purchasing->supplier($this->warehouse, 'Supplier', 'Details', $key, $contact));
        $this->assertDatabaseHas('suppliers', ['id' => $result['id'], ...$contact]);
        Livewire::actingAs($this->warehouse)->test(Stock::class)->assertSee('supplier@example.test');
        $this->rejects(fn () => $purchasing->supplier($this->warehouse, 'Invalid', 'Details', $this->key(), ['contact_email' => 'not-an-email']));
        $this->assertDatabaseCount('suppliers', 1);
        $id = $purchasing->supplier($this->warehouse, 'No contact', 'Details', $this->key())['id'];
        $this->assertDatabaseHas('suppliers', ['id' => $id, 'contact_email' => null]);
    }

    public function test_multiline_purchase_form_retains_identity_and_posts_without_receipt(): void
    {
        $id = $this->idleMaterial();
        $supplier = app(Purchasing::class)->supplier($this->warehouse, 'Supplier', 'Details', $this->key())['id'];
        $page = Livewire::actingAs($this->warehouse)->test(Stock::class)->set('task', 'order')->call('addPurchaseLine')->call('addPurchaseLine');
        $lineKey = $page->get('additionalLines.1.key');
        $page->set('additionalLines.1.material_id', $id)->set('additionalLines.1.quantity', '2.125')->set('additionalLines.1.unit_cost', '3.45')->call('removePurchaseLine', 0)->assertSet('additionalLines.0.key', $lineKey)->assertSet('additionalLines.0.quantity', '2.125');
        $page->set('supplier', $supplier)->set('from', $this->store)->set('material', $this->material)->set('quantity', '1')->set('cost', '100')->set('reason', 'Restock two materials')->call('post')->assertHasNoErrors()->assertSet('additionalLines', []);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_lines', 2);
        $this->assertDatabaseHas('purchase_lines', ['material_id' => $id, 'quantity_milli' => 2125, 'unit_cost_micro' => 345000000]);
        $this->assertDatabaseCount('stock_documents', 1);
        $line = DB::table('purchase_lines')->where('material_id', $id)->value('id');
        $page->set('task', 'receive')->set('basis', $line)->set('quantity', '1')->set('reason', 'First delivery')->call('post')->assertHasNoErrors()->assertSet('version', 2);
        $page->set('quantity', '1.125')->set('reason', 'Remainder')->call('post')->assertHasNoErrors()->assertSet('version', 3);
        $this->assertDatabaseHas('purchase_orders', ['status' => 'partially_received', 'version' => 3]);
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_invalid_extra_purchase_line_rolls_back_order_and_sequence(): void
    {
        $supplier = app(Purchasing::class)->supplier($this->warehouse, 'Supplier', 'Details', $this->key())['id'];
        $id = $this->idleMaterial();
        $base = ['supplier_id' => $supplier, 'location_id' => $this->store, 'expected_on' => now()->format('Y-m-d'), 'reason' => 'Restock'];
        foreach ([['material_id' => $this->material, 'quantity' => '1', 'unit_cost' => '1'], ['material_id' => $id, 'quantity' => '0', 'unit_cost' => '1'], ['material_id' => $id, 'quantity' => '1', 'unit_cost' => '-1']] as $extra) {
            $input = [...$base, 'lines' => [['material_id' => $this->material, 'quantity' => '1', 'unit_cost' => '1'], $extra]];
            $this->rejects(fn () => app(Purchasing::class)->order($this->warehouse, $input, $this->key()));
        }
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_lines', 0);
        $this->assertSame(0, DB::table('document_sequences')->where('kind', 'PO')->count());
    }

    private function databaseRejects(callable $action, string $message): void
    {
        try {
            DB::transaction($action);
            $this->fail('Invalid inventory row persisted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function balanceRows(): array
    {
        $allocation = $this->allocation();
        [, $line] = $this->purchase();

        return [
            'material_demands' => (array) DB::table('material_demands')->first(),
            'stock_reservations' => (array) DB::table('stock_reservations')->first(),
            'stock_allocations' => (array) DB::table('stock_allocations')->find($allocation),
            'purchase_lines' => (array) DB::table('purchase_lines')->find($line),
            'stock_positions' => (array) DB::table('stock_positions')->where('stock_location_id', $this->store)->first(),
        ];
    }

    public function test_database_rejects_invalid_balance_inserts_and_updates(): void
    {
        $rows = $this->balanceRows();
        $unusedMaterial = $this->idleMaterial();
        $cases = [
            'material_demands' => [['quantity_milli' => -1], ['quantity_milli' => 1.5], ['quantity_milli' => 'invalid']],
            'stock_reservations' => [['remaining_milli' => -1], ['remaining_milli' => 1.5]],
            'stock_allocations' => [['remaining_milli' => -1], ['remaining_milli' => 3001], ['remaining_milli' => 1.5], ['remaining_value_micro' => -1], ['remaining_value_micro' => 1.5], ['remaining_milli' => 0, 'remaining_value_micro' => 1]],
            'purchase_lines' => [['quantity_milli' => 0], ['quantity_milli' => 1.5], ['received_milli' => -1], ['received_milli' => 2001], ['received_milli' => 1.5], ['unit_cost_micro' => -1], ['unit_cost_micro' => 1.5]],
            'stock_positions' => [['quantity_milli' => -1], ['quantity_milli' => 1.5], ['reserved_milli' => -1], ['reserved_milli' => 7001], ['reserved_milli' => 1.5], ['value_micro' => -1], ['value_micro' => 1.5], ['quantity_milli' => 0, 'reserved_milli' => 0, 'value_micro' => 1]],
        ];
        foreach ($cases as $table => $changesets) {
            foreach ($changesets as $changes) {
                $this->databaseRejects(fn () => DB::table($table)->where('id', $rows[$table]['id'])->update($changes), 'Invalid inventory balance');
                $insert = $rows[$table];
                unset($insert['id']);
                if (in_array($table, ['purchase_lines', 'stock_positions'])) {
                    $insert['material_id'] = $unusedMaterial;
                }
                $this->databaseRejects(fn () => DB::table($table)->insert([...$insert, ...$changes]), 'Invalid inventory balance');
            }
            $this->assertSame($rows[$table], (array) DB::table($table)->find($rows[$table]['id']));
        }
        $insert = $rows['stock_allocations'];
        unset($insert['id']);
        foreach ([0, -1, 1.5] as $issued) {
            $this->databaseRejects(fn () => DB::table('stock_allocations')->insert([...$insert, 'issued_milli' => $issued]), 'Invalid inventory balance');
        }
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_database_preserves_inventory_reference_identities(): void
    {
        $rows = $this->balanceRows();
        [$otherOrder] = $this->purchase();
        $otherMaterial = $this->idleMaterial();
        $otherPackage = $this->package->replicate();
        $otherPackage->ordinal = 2;
        $otherPackage->save();
        $otherDemand = $this->stock->demand($this->dispatcher, $otherPackage->id, $this->material, '1', 'Other scope', $this->key())['id'];
        $cases = [
            'material_demands' => ['material_id' => $otherMaterial, 'work_package_id' => $otherPackage->id],
            'stock_reservations' => ['material_demand_id' => $otherDemand, 'stock_location_id' => $this->bag],
            'stock_allocations' => ['material_demand_id' => $otherDemand, 'stock_location_id' => $this->store, 'issued_milli' => 4000],
            'purchase_lines' => ['purchase_order_id' => $otherOrder, 'material_id' => $otherMaterial, 'material_demand_id' => $otherDemand],
        ];
        foreach ($cases as $table => $changes) {
            foreach ($changes as $column => $value) {
                $this->databaseRejects(fn () => DB::table($table)->where('id', $rows[$table]['id'])->update([$column => $value]), 'Inventory basis is immutable');
            }
            DB::table($table)->where('id', $rows[$table]['id'])->update($rows[$table]);
            $this->assertSame($rows[$table], (array) DB::table($table)->find($rows[$table]['id']));
        }
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_inventory_constraint_migration_round_trip_preserves_existing_guards(): void
    {
        $migration = require database_path('migrations/2026_11_12_072415_add_inventory_balance_constraints.php');
        $migration->down();
        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')->where(fn ($query) => $query->where('name', 'like', '%_operational_bounds_%')->orWhere('name', 'like', '%_identity_guard'))->count());
        $this->databaseRejects(fn () => DB::table('stock_entries')->update(['quantity_delta' => 0]), 'Posted stock is immutable');
        $this->databaseRejects(fn () => DB::table('materials')->where('id', $this->material)->update(['version' => 0]), 'Invalid material catalog value');
        $migration->up();
        $this->assertSame(14, DB::table('sqlite_master')->where('type', 'trigger')->where(fn ($query) => $query->where('name', 'like', '%_operational_bounds_%')->orWhere('name', 'like', '%_identity_guard'))->count());
        $this->databaseRejects(fn () => DB::table('stock_positions')->update(['quantity_milli' => 0, 'value_micro' => 1]), 'Invalid inventory balance');
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_inventory_migration_refuses_invalid_existing_rows_before_installing_guards(): void
    {
        $rows = $this->balanceRows();
        $migration = require database_path('migrations/2026_11_12_072415_add_inventory_balance_constraints.php');
        $migration->down();
        DB::table('purchase_lines')->where('id', $rows['purchase_lines']['id'])->update(['received_milli' => 2001]);
        try {
            $migration->up();
            $this->fail('Invalid existing balance accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Resolve invalid inventory balances in purchase_lines before migrating.', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', '%_operational_bounds_%')->count());
        DB::table('purchase_lines')->where('id', $rows['purchase_lines']['id'])->update(['received_milli' => 0]);
        $migration->up();
        $this->databaseRejects(fn () => DB::table('purchase_lines')->where('id', $rows['purchase_lines']['id'])->update(['received_milli' => 2001]), 'Invalid inventory balance');
        $this->assertTrue($this->stock->reconcile());
    }

    public function test_warehouse_authority_is_required(): void
    {
        $this->expectException(HttpException::class);
        $this->stock->opening($this->tech, $this->material, $this->store, '1', '1', 'Unauthorized', $this->key());
    }

    public function test_approve_demand_refreshes_context_for_repeat_action(): void
    {
        $r = ServiceRequest::findOrFail($this->package->service_request_id);
        $this->package->increment('version');
        $page = Livewire::actingAs($this->dispatcher)->test(RequestDetail::class, ['id' => $r->id])
            ->set('demandPackage', $this->package->id)
            ->set('demandMaterial', $this->material)
            ->set('demandQuantity', '1')
            ->set('demandReason', 'Need')
            ->call('approveDemand')->assertHasNoErrors();
        $this->assertSame($this->package->fresh()->version, $page->get('packageVersions')[$this->package->id]);
    }
}
