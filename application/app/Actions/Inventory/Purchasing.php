<?php

namespace App\Actions\Inventory;

use App\Actions\Operation;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\Fixed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Purchasing
{
    public function __construct(private Operation $operations, private Inventory $inventory) {}

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['purchase' => $message]);
        }
    }

    private function audit(User $actor, string $action, array $data): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => $action, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function supplier(User $actor, string $name, string $details, string $key, array $contact = []): array
    {
        $this->inventory->authorize($actor);
        Validator::make(['name' => trim($name), 'details' => trim($details)], ['name' => 'required|string|max:255', 'details' => 'required|string|max:5000'])->validate();
        $contact = Validator::make($contact, ['contact_name' => 'nullable|string|max:255', 'contact_email' => 'nullable|email|max:255', 'contact_phone' => 'nullable|string|max:100'])->validate();

        return $this->operations->run($actor, $key, 'purchase.supplier', [$name, $details, $contact], function () use ($actor, $name, $details, $contact) {
            $this->inventory->authorize($actor);
            $id = DB::table('suppliers')->insertGetId(['name' => trim($name), 'details' => trim($details), ...$contact, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'purchase.supplier_created', ['supplier_id' => $id]);

            return ['id' => $id];
        });
    }

    public function order(User $actor, array $input, string $key): array
    {
        $this->inventory->authorize($actor);
        $input = Validator::make($input, ['supplier_id' => 'required|integer', 'location_id' => 'required|integer', 'expected_on' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:5000', 'lines' => 'required|array|min:1|max:100', 'lines.*.material_id' => 'required|integer|distinct', 'lines.*.quantity' => 'required|string', 'lines.*.unit_cost' => 'required|string', 'lines.*.demand_id' => 'nullable|integer'])->validate();

        return $this->operations->run($actor, $key, 'purchase.order', $input, function () use ($actor, $input) {
            $this->inventory->authorize($actor);
            $this->check((bool) DB::table('suppliers')->where('id', $input['supplier_id'])->value('active'), 'Выберите действующего поставщика.');
            $this->check(DB::table('stock_locations')->where('id', $input['location_id'])->where('kind', 'warehouse')->where('active', true)->exists(), 'Выберите действующий склад назначения.');
            $this->check(trim($input['reason']) !== '', 'Укажите основание закупки.');
            $id = DB::table('purchase_orders')->insertGetId(['number' => DocumentNumber::next('PO'), 'actor_id' => $actor->id, 'supplier_id' => $input['supplier_id'], 'stock_location_id' => $input['location_id'], 'expected_on' => $input['expected_on'], 'reason' => trim($input['reason']), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($input['lines'] as $line) {
                $quantity = Fixed::quantity($line['quantity']);
                $this->check($quantity > 0 && (bool) DB::table('materials')->where('id', $line['material_id'])->value('active'), 'Укажите действующий материал и положительное количество.');
                if (! empty($line['demand_id'])) {
                    $this->check(DB::table('material_demands')->where('id', $line['demand_id'])->where('material_id', $line['material_id'])->where('status', 'approved')->exists(), 'Связанная потребность не подходит этому материалу.');
                }
                DB::table('purchase_lines')->insert(['purchase_order_id' => $id, 'material_id' => $line['material_id'], 'material_demand_id' => $line['demand_id'] ?? null, 'quantity_milli' => $quantity, 'unit_cost_micro' => Fixed::money($line['unit_cost']) * 1000000, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit($actor, 'purchase.ordered', ['order_id' => $id, 'reason' => trim($input['reason'])]);

            return ['id' => $id];
        });
    }

    public function receive(User $actor, int $lineId, int $version, string $quantity, string $reason, string $key): array
    {
        $this->inventory->authorize($actor);

        return $this->operations->run($actor, $key, 'purchase.receive', [$lineId, $version, $quantity, $reason], function () use ($actor, $lineId, $version, $quantity, $reason) {
            $this->inventory->authorize($actor);
            $line = DB::table('purchase_lines')->where('id', $lineId)->firstOrFail();
            $order = DB::table('purchase_orders')->where('id', $line->purchase_order_id)->lockForUpdate()->first();
            $line = DB::table('purchase_lines')->where('id', $lineId)->lockForUpdate()->first();
            $q = Fixed::quantity($quantity);
            $this->check($order->version === $version && in_array($order->status, ['ordered', 'partially_received']), 'Заказ уже изменился или отменён. Обновите данные.');
            $this->check($q > 0 && $q <= $line->quantity_milli - $line->received_milli, 'Поступление превышает неотгруженный остаток заказа.');
            $doc = $this->inventory->receiptLocked($actor, $line, $order, $q, $reason);
            DB::table('purchase_lines')->where('id', $lineId)->increment('received_milli', $q);
            $remaining = DB::table('purchase_lines')->where('purchase_order_id', $order->id)->whereColumn('received_milli', '<', 'quantity_milli')->exists();
            DB::table('purchase_orders')->where('id', $order->id)->update(['status' => $remaining ? 'partially_received' : 'received', 'version' => $order->version + 1, 'updated_at' => now()]);
            $this->audit($actor, 'purchase.received', ['order_id' => $order->id, 'line_id' => $lineId, 'document_id' => $doc, 'quantity_milli' => $q, 'reason' => trim($reason)]);

            return ['id' => $doc];
        });
    }

    public function amend(User $actor, int $lineId, int $version, string $quantity, string $cost, string $expected, string $reason, string $key): array
    {
        $this->inventory->authorize($actor);

        return $this->operations->run($actor, $key, 'purchase.amend', [$lineId, $version, $quantity, $cost, $expected, $reason], function () use ($actor, $lineId, $version, $quantity, $cost, $expected, $reason) {
            $this->inventory->authorize($actor);
            Validator::make(['reason' => trim($reason), 'expected' => $expected], ['reason' => 'required|string|max:5000', 'expected' => 'required|date_format:Y-m-d'])->validate();
            $line = DB::table('purchase_lines')->where('id', $lineId)->firstOrFail();
            $order = DB::table('purchase_orders')->where('id', $line->purchase_order_id)->lockForUpdate()->firstOrFail();
            $line = DB::table('purchase_lines')->where('id', $lineId)->lockForUpdate()->firstOrFail();
            $q = Fixed::quantity($quantity);
            $unitCost = Fixed::money($cost) * 1000000;
            $this->check($order->version === $version && in_array($order->status, ['ordered', 'partially_received']) && $q > 0 && $q >= $line->received_milli, 'Заказ изменён, закрыт или новое количество меньше полученного.');
            DB::table('purchase_lines')->where('id', $lineId)->update(['quantity_milli' => $q, 'unit_cost_micro' => $unitCost, 'updated_at' => now()]);
            $remaining = DB::table('purchase_lines')->where('purchase_order_id', $order->id)->whereColumn('received_milli', '<', 'quantity_milli')->exists();
            DB::table('purchase_orders')->where('id', $order->id)->update(['status' => $remaining ? $order->status : 'received', 'expected_on' => $expected, 'version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, 'purchase.amended', ['order_id' => $order->id, 'line_id' => $lineId, 'before' => ['quantity_milli' => $line->quantity_milli, 'unit_cost_micro' => $line->unit_cost_micro, 'expected_on' => $order->expected_on], 'after' => ['quantity_milli' => $q, 'unit_cost_micro' => $unitCost, 'expected_on' => $expected], 'reason' => trim($reason)]);

            return ['id' => $order->id];
        });
    }

    public function cancel(User $actor, int $id, int $version, string $reason, string $key): array
    {
        $this->inventory->authorize($actor);

        return $this->operations->run($actor, $key, 'purchase.cancel', [$id, $version, $reason], function () use ($actor, $id, $version, $reason) {
            $this->inventory->authorize($actor);
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:5000'])->validate();
            $order = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->check($order->version === $version && in_array($order->status, ['ordered', 'partially_received']), 'Заказ уже изменился или полностью получен.');
            DB::table('purchase_orders')->where('id', $id)->update(['status' => 'cancelled', 'version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, 'purchase.cancelled', ['order_id' => $id, 'reason' => trim($reason)]);

            return ['id' => $id];
        });
    }
}
