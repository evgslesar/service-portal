<?php

namespace App\Livewire;

use App\Actions\Inventory\Inventory;
use App\Actions\Inventory\Purchasing;
use App\Models\User;
use App\Models\WorkPackage;
use App\Support\Fixed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Stock extends TaskPage
{
    public string $task = 'opening';

    public string $name = '';

    public string $code = '';

    public string $unit = '';

    public string $quantity = '';

    public string $cost = '';

    public string $reason = '';

    public string $details = '';

    public string $sellingRate = '';

    public string $description = '';

    public bool $active = true;

    public array $contact = ['contact_name' => '', 'contact_email' => '', 'contact_phone' => ''];

    public array $additionalLines = [];

    public string $expected = '';

    public ?int $material = null;

    public ?int $from = null;

    public ?int $to = null;

    public ?int $custodian = null;

    public ?int $package = null;

    public ?int $supplier = null;

    public ?int $basis = null;

    public ?int $demand = null;

    public int $version = 1;

    public function mount(): void
    {
        app(Inventory::class)->authorize($this->actor());
        $this->prepareKeys(['post']);
        $this->expected = now()->format('Y-m-d');
    }

    public function addPurchaseLine(): void
    {
        app(Inventory::class)->authorize($this->actor());
        abort_unless(count($this->additionalLines) < 99, 422);
        $this->additionalLines[] = ['key' => (string) Str::uuid(), 'material_id' => '', 'quantity' => '', 'unit_cost' => '', 'demand_id' => null];
    }

    public function removePurchaseLine(int $index): void
    {
        app(Inventory::class)->authorize($this->actor());
        unset($this->additionalLines[$index]);
        $this->additionalLines = array_values($this->additionalLines);
    }

    public function editMaterial(int $id): void
    {
        app(Inventory::class)->authorize($this->actor());
        $material = DB::table('materials')->where('id', $id)->firstOrFail();
        $this->task = 'material-update';
        $this->material = $id;
        $this->code = $material->code;
        $this->name = $material->name;
        $this->unit = $material->unit;
        $this->sellingRate = $material->default_rate_kopecks === null ? '' : Fixed::display($material->default_rate_kopecks, 2);
        $this->description = $material->description ?? '';
        $this->active = (bool) $material->active;
        $this->version = $material->version;
        $this->reason = '';
        $this->rotateKey('post');
    }

    public function post(Inventory $inventory, Purchasing $purchasing): void
    {
        $inventory->authorize($this->actor());
        $this->validate(['task' => 'required|in:material,material-update,location,opening,transfer,demand,reserve,release,issue,return,count,custodian,supplier,order,receive,cancel,reverse,amend,rebuild', 'reason' => 'required|string|max:5000']);
        $actor = $this->actor();
        $key = $this->key('post');
        $catalog = ['code' => $this->code, 'name' => $this->name, 'unit' => $this->unit, 'reason' => $this->reason, 'default_rate' => $this->sellingRate, 'description' => $this->description, 'active' => $this->active];
        match ($this->task) {
            'material' => $inventory->material($actor, $catalog, $key),
            'material-update' => $inventory->updateMaterial($actor, (int) $this->material, $this->version, $catalog, $key),
            'location' => $inventory->location($actor, $this->name, $this->custodian, $this->reason, $key),
            'opening' => $inventory->opening($actor, (int) $this->material, (int) $this->from, $this->quantity, $this->cost, $this->reason, $key),
            'transfer' => $inventory->transfer($actor, (int) $this->material, (int) $this->from, (int) $this->to, $this->quantity, $this->reason, $key),
            'demand' => $inventory->demand($actor, (int) $this->package, (int) $this->material, $this->quantity, $this->reason, $key),
            'reserve' => $inventory->reserve($actor, (int) $this->basis, (int) $this->from, $this->quantity, $this->reason, $key),
            'release' => $inventory->release($actor, (int) $this->basis, $this->quantity, $this->reason, $key),
            'issue' => $inventory->issue($actor, (int) $this->basis, (int) $this->to, $this->quantity, $this->reason, $key),
            'return' => $inventory->returnUnused($actor, (int) $this->basis, (int) $this->to, $this->quantity, $this->reason, $key),
            'count' => $inventory->count($actor, (int) $this->material, (int) $this->from, $this->quantity, $this->reason, $key),
            'custodian' => $inventory->reassignCustodian($actor, (int) $this->from, (int) $this->custodian, $this->reason, $key),
            'supplier' => $purchasing->supplier($actor, $this->name, $this->details, $key, $this->contact),
            'order' => $purchasing->order($actor, ['supplier_id' => $this->supplier, 'location_id' => $this->from, 'expected_on' => $this->expected, 'reason' => $this->reason, 'lines' => [['material_id' => $this->material, 'quantity' => $this->quantity, 'unit_cost' => $this->cost, 'demand_id' => $this->demand], ...$this->additionalLines]], $key),
            'receive' => $purchasing->receive($actor, (int) $this->basis, $this->version, $this->quantity, $this->reason, $key),
            'cancel' => $purchasing->cancel($actor, (int) $this->basis, $this->version, $this->reason, $key),
            'reverse' => $inventory->reverse($actor, (int) $this->basis, $this->reason, $key),
            'amend' => $purchasing->amend($actor, (int) $this->basis, $this->version, $this->quantity, $this->cost, $this->expected, $this->reason, $key),
            'rebuild' => $inventory->rebuild($actor, $this->reason, $key),
        };
        $this->done('post', $this->task === 'rebuild' ? 'Остатки восстановлены из неизменяемой истории.' : 'Операция сохранена. Проведённая история не изменена.');
        if ($this->task === 'material-update') {
            $this->version = DB::table('materials')->where('id', $this->material)->value('version');
        } elseif (in_array($this->task, ['amend', 'receive', 'cancel'])) {
            $order = $this->task === 'cancel' ? $this->basis : DB::table('purchase_lines')->where('id', $this->basis)->value('purchase_order_id');
            $this->version = DB::table('purchase_orders')->where('id', $order)->value('version');
        }
        $this->reason = '';
        $this->quantity = '';
        $this->additionalLines = [];
    }

    public function render()
    {
        app(Inventory::class)->authorize($this->actor());
        $basisTable = match ($this->task) {
            'reserve' => 'material_demands', 'release', 'issue' => 'stock_reservations', 'return' => 'stock_allocations', 'receive', 'amend' => 'purchase_lines', 'cancel' => 'purchase_orders', 'reverse' => 'stock_documents', default => null
        };
        /** @var Collection<int, User> $technicians */
        $technicians = User::where('active', true)->get();

        return view('livewire.stock', [
            'materials' => DB::table('materials')->orderBy('name')->get(),
            'demands' => DB::table('material_demands')->where('status', 'approved')->when($this->material, fn ($query) => $query->where('material_id', $this->material))->orderByDesc('id')->limit(100)->get(),
            'shortages' => app(Inventory::class)->shortages($this->actor()),
            'purchaseLines' => DB::table('purchase_lines')->join('materials', 'materials.id', '=', 'purchase_lines.material_id')->get(['purchase_lines.*', 'materials.name', 'materials.unit'])->groupBy('purchase_order_id'),
            'locations' => DB::table('stock_locations')->orderBy('name')->get(),
            'technicians' => $technicians->filter(fn (User $u) => $u->hasRole('technician')),
            'packages' => WorkPackage::whereNotIn('status', ['accepted', 'cancelled'])->with('request')->orderByDesc('id')->limit(100)->get(),
            'suppliers' => DB::table('suppliers')->where('active', true)->get(),
            'bases' => $basisTable ? DB::table($basisTable)->orderByDesc('id')->limit(100)->get() : collect(),
            'positions' => DB::table('stock_positions')->join('stock_locations', 'stock_locations.id', '=', 'stock_positions.stock_location_id')->join('materials', 'materials.id', '=', 'stock_positions.material_id')->orderBy('stock_locations.name')->get(['stock_locations.name as location', 'materials.name as material', 'materials.unit', 'quantity_milli', 'reserved_milli']),
            'documents' => DB::table('stock_documents')->orderByDesc('id')->limit(30)->get(),
            'orders' => DB::table('purchase_orders')->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')->orderByDesc('purchase_orders.id')->limit(30)->get(['purchase_orders.*', 'suppliers.name']),
        ]);
    }
}
