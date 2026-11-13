<?php

namespace App\Actions\Inventory;

use App\Actions\Operation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPackage;
use App\Support\DocumentNumber;
use App\Support\Fixed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public function __construct(private Operation $operations) {}

    public function lockStock(): void
    {
        // Request/package locks precede this pilot-wide stock mutex; user locks follow it.
        abort_unless(DB::transactionLevel() > 0, 500);
        DB::table('stock_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
    }

    public function authorize(User $actor): void
    {
        abort_unless($actor->fresh()?->hasRole('warehouse'), 403);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['stock' => $message]);
        }
    }

    private function reason(string $reason): string
    {
        return Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:5000'])->validate()['reason'];
    }

    private function audit(User $actor, string $action, array $data, ?int $requestId = null): void
    {
        if ($requestId) {
            $this->operations->event($actor, $requestId, $action, $data);

            return;
        }
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => $action, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function locations(array $ids): array
    {
        $rows = DB::table('stock_locations')->whereIn('id', $ids)->orderBy('id')->get();
        $users = User::whereIn('id', $rows->pluck('custodian_id')->filter()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->check($rows->count() === count(array_unique($ids)), 'Выберите существующие места хранения.');
        foreach ($rows as $row) {
            $this->check((bool) $row->active && (! $row->custodian_id || ($users[$row->custodian_id]?->hasRole('technician') ?? false)), 'Место хранения или ответственный мастер неактивны.');
        }

        return $rows->keyBy('id')->all();
    }

    private function positions(int $materialId, array $locations): array
    {
        $this->check((bool) DB::table('materials')->where('id', $materialId)->value('active'), 'Материал неактивен или отсутствует.');
        sort($locations);
        foreach (array_unique($locations) as $location) {
            DB::table('stock_positions')->insertOrIgnore(['material_id' => $materialId, 'stock_location_id' => $location, 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('stock_positions')->where('material_id', $materialId)->whereIn('stock_location_id', $locations)->orderBy('stock_location_id')->lockForUpdate()->get()->keyBy('stock_location_id')->all();
    }

    private function document(User $actor, string $kind, string $reason, array $basis = []): int
    {
        return DB::table('stock_documents')->insertGetId(['number' => DocumentNumber::next('STK'), 'actor_id' => $actor->id, 'kind' => $kind, 'reason' => $this->reason($reason), ...$basis, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(int $document, object $position, int $quantity, int $reserved, int $value): void
    {
        $q = $position->quantity_milli + $quantity;
        $r = $position->reserved_milli + $reserved;
        $v = $position->value_micro + $value;
        $this->check(is_int($q) && is_int($r) && is_int($v) && $q >= 0 && $r >= 0 && $r <= $q && $v >= 0 && ($q !== 0 || $v === 0), 'Недостаточно свободного остатка или неверная стоимость.');
        DB::table('stock_positions')->where('id', $position->id)->update(['quantity_milli' => $q, 'reserved_milli' => $r, 'value_micro' => $v, 'updated_at' => now()]);
        DB::table('stock_entries')->insert(['stock_document_id' => $document, 'stock_location_id' => $position->stock_location_id, 'material_id' => $position->material_id, 'quantity_delta' => $quantity, 'reserved_delta' => $reserved, 'value_delta' => $value, 'created_at' => now(), 'updated_at' => now()]);
        $position->quantity_milli = $q;
        $position->reserved_milli = $r;
        $position->value_micro = $v;
    }

    private function package(int $id, bool $open = true): WorkPackage
    {
        $p = WorkPackage::findOrFail($id);
        $root = ServiceRequest::whereKey($p->service_request_id)->lockForUpdate()->firstOrFail();
        $p = WorkPackage::whereKey($id)->lockForUpdate()->firstOrFail();
        $this->lockStock();
        if ($open) {
            $this->check(! in_array($root->status, ['closed', 'cancelled']) && ! in_array($p->status, ['accepted', 'cancelled', 'awaiting_acceptance']), 'Объём работ уже завершён или ожидает приёмки.');
        }

        return $p;
    }

    public function material(User $actor, array $input, string $key): array
    {
        $this->authorize($actor);
        $input = $this->catalogInput($input);

        return $this->operations->run($actor, $key, 'stock.material', $input, function () use ($actor, $input) {
            $this->authorize($actor);
            $this->check(! DB::table('materials')->where('code', $input['code'])->exists(), 'Код материала уже используется.');
            $id = DB::table('materials')->insertGetId(collect($input)->except('reason')->all() + ['created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'stock.material_created', ['material_id' => $id, 'reason' => $this->reason($input['reason'])]);

            return ['id' => $id];
        });
    }

    private function catalogInput(array $input): array
    {
        $input = Validator::make($input, ['code' => 'required|string|max:100', 'name' => 'required|string|max:255', 'unit' => 'required|string|max:50', 'reason' => 'required|string|max:5000', 'default_rate' => 'nullable|string', 'description' => 'nullable|string|max:5000', 'active' => 'sometimes|boolean'])->validate();
        foreach (['code', 'name', 'unit', 'reason'] as $field) {
            $input[$field] = trim($input[$field]);
            $this->check($input[$field] !== '', 'Заполните код, название, единицу и основание.');
        }
        if (array_key_exists('default_rate', $input)) {
            $input['default_rate_kopecks'] = filled($input['default_rate']) ? Fixed::money($input['default_rate']) : null;
            unset($input['default_rate']);
        }
        if (array_key_exists('active', $input)) {
            $input['active'] = (bool) $input['active'];
        }

        return $input;
    }

    public function updateMaterial(User $actor, int $id, int $version, array $input, string $key): array
    {
        $this->authorize($actor);
        $input = $this->catalogInput($input);

        return $this->operations->run($actor, $key, 'stock.material_update', [$id, $version, $input], function () use ($actor, $id, $version, $input) {
            $this->authorize($actor);
            $this->lockStock();
            $material = DB::table('materials')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->check($material->version === $version, 'Карточка материала изменилась. Обновите версию.');
            $this->check(! DB::table('materials')->where('code', $input['code'])->where('id', '!=', $id)->exists(), 'Код материала уже используется.');
            $hasHistory = DB::table('stock_entries')->where('material_id', $id)->exists()
                || DB::table('material_demands')->where('material_id', $id)->exists()
                || DB::table('purchase_lines')->where('material_id', $id)->exists()
                || DB::table('estimates')->get(['lines'])->contains(fn ($estimate) => collect(json_decode($estimate->lines, true, flags: JSON_THROW_ON_ERROR))->contains('material_id', $id));
            $this->check(! $hasHistory || $material->unit === $input['unit'], 'Единица материала с историей не меняется. Добавьте отдельный материал.');
            if (($input['active'] ?? true) === false) {
                $busy = DB::table('stock_positions')->where('material_id', $id)->where('quantity_milli', '>', 0)->exists()
                    || DB::table('material_demands')->where('material_id', $id)->where('status', 'approved')->where('quantity_milli', '>', 0)->exists()
                    || DB::table('purchase_lines')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_lines.purchase_order_id')->where('material_id', $id)->whereIn('purchase_orders.status', ['ordered', 'partially_received'])->whereColumn('purchase_lines.received_milli', '<', 'purchase_lines.quantity_milli')->exists();
                $pendingQuote = DB::table('estimates')->leftJoin('estimate_decisions', 'estimate_decisions.estimate_id', '=', 'estimates.id')->join('service_requests', 'service_requests.id', '=', 'estimates.service_request_id')->whereNull('estimate_decisions.id')->whereNotIn('service_requests.status', ['closed', 'cancelled'])->get(['estimates.lines'])->contains(fn ($estimate) => collect(json_decode($estimate->lines, true, flags: JSON_THROW_ON_ERROR))->contains('material_id', $id));
                $this->check(! $busy && ! $pendingQuote, 'Сначала разрешите остатки, потребности, неполученные заказы и нерассмотренные сметы материала.');
            }
            $changes = collect($input)->except('reason')->all() + ['version' => $version + 1, 'updated_at' => now()];
            DB::table('materials')->where('id', $id)->update($changes);
            $this->audit($actor, 'stock.material_updated', ['material_id' => $id, 'before' => (array) $material, 'after' => $changes, 'reason' => $input['reason']]);

            return ['id' => $id];
        });
    }

    public function location(User $actor, string $name, ?int $custodianId, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.location', [$name, $custodianId, $reason], function () use ($actor, $name, $custodianId, $reason) {
            $this->authorize($actor);
            Validator::make(['name' => trim($name)], ['name' => 'required|string|max:255'])->validate();
            if ($custodianId) {
                $this->check(User::whereKey($custodianId)->lockForUpdate()->first()?->hasRole('technician') ?? false, 'Выберите действующего ответственного мастера.');
            }
            $id = DB::table('stock_locations')->insertGetId(['name' => trim($name), 'kind' => $custodianId ? 'technician' : 'warehouse', 'custodian_id' => $custodianId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'stock.location_created', ['location_id' => $id, 'custodian_id' => $custodianId, 'reason' => $this->reason($reason)]);

            return ['id' => $id];
        });
    }

    public function opening(User $actor, int $material, int $location, string $quantity, string $unitCost, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.opening', [$material, $location, $quantity, $unitCost, $reason], function () use ($actor, $material, $location, $quantity, $unitCost, $reason) {
            $this->authorize($actor);
            $this->locations([$location]);
            $q = Fixed::quantity($quantity);
            $this->check($q > 0, 'Количество должно быть больше нуля.');
            $position = $this->positions($material, [$location])[$location];
            $value = Fixed::ratio(Fixed::money($unitCost) * 1000000, $q, 1000);
            $id = $this->document($actor, 'opening', $reason);
            $this->entry($id, $position, $q, 0, $value);
            $this->audit($actor, 'stock.opened', ['document_id' => $id]);

            return ['id' => $id];
        });
    }

    public function transfer(User $actor, int $material, int $from, int $to, string $quantity, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.transfer', [$material, $from, $to, $quantity, $reason], function () use ($actor, $material, $from, $to, $quantity, $reason) {
            $this->authorize($actor);
            $this->check($from !== $to, 'Выберите другое место хранения.');
            $this->locations([$from, $to]);
            $q = Fixed::quantity($quantity);
            $positions = $this->positions($material, [$from, $to]);
            $source = $positions[$from];
            $held = DB::table('stock_allocations')->join('material_demands', 'material_demands.id', '=', 'stock_allocations.material_demand_id')->where('stock_location_id', $from)->where('material_id', $material)->sum('remaining_milli');
            $this->check($q > 0 && $q <= $source->quantity_milli - $source->reserved_milli - $held, 'Зарезервированное или выданное на работы количество нельзя перемещать без освобождения/возврата.');
            $heldValue = DB::table('stock_allocations')->join('material_demands', 'material_demands.id', '=', 'stock_allocations.material_demand_id')->where('stock_location_id', $from)->where('material_id', $material)->sum('remaining_value_micro');
            $cost = Fixed::ratio($source->value_micro - $heldValue, $q, $source->quantity_milli - $held);
            $id = $this->document($actor, 'transfer', $reason);
            $this->entry($id, $source, -$q, 0, -$cost);
            $this->entry($id, $positions[$to], $q, 0, $cost);
            $this->audit($actor, 'stock.transferred', ['document_id' => $id]);

            return ['id' => $id];
        });
    }

    public function demand(User $actor, int $packageId, int $material, string $quantity, string $reason, string $key): array
    {
        abort_unless($actor->fresh()?->hasRole('dispatcher'), 403);

        return $this->operations->run($actor, $key, 'stock.demand', [$packageId, $material, $quantity, $reason], function () use ($actor, $packageId, $material, $quantity, $reason) {
            $p = $this->package($packageId);
            abort_unless($actor->fresh()?->hasRole('dispatcher'), 403);
            $this->check(in_array($p->kind, ['covered', 'diagnostic', 'maintenance']) && $p->allowsExecution(), 'Платные материалы разрешаются только утверждённой сметой.');
            $q = Fixed::quantity($quantity);
            $this->check($q > 0 && (bool) DB::table('materials')->where('id', $material)->value('active'), 'Выберите материал и положительное количество.');
            $id = DB::table('material_demands')->insertGetId(['work_package_id' => $packageId, 'material_id' => $material, 'actor_id' => $actor->id, 'quantity_milli' => $q, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'stock.demand_approved', ['package_id' => $packageId, 'demand_id' => $id], $p->service_request_id);

            return ['id' => $id];
        });
    }

    public function reserve(User $actor, int $demandId, int $location, string $quantity, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.reserve', [$demandId, $location, $quantity, $reason], function () use ($actor, $demandId, $location, $quantity, $reason) {
            $demand = DB::table('material_demands')->where('id', $demandId)->firstOrFail();
            $p = $this->package($demand->work_package_id);
            $this->authorize($actor);
            $locations = $this->locations([$location]);
            $this->check($locations[$location]->kind === 'warehouse', 'Резерв создаётся на складе.');
            $demand = DB::table('material_demands')->where('id', $demandId)->lockForUpdate()->first();
            $q = Fixed::quantity($quantity);
            $reserved = DB::table('stock_reservations')->where('material_demand_id', $demandId)->sum('remaining_milli');
            $issued = DB::table('stock_allocations')->where('material_demand_id', $demandId)->sum('issued_milli');
            $returned = DB::table('stock_documents')->join('stock_allocations', 'stock_allocations.id', '=', 'stock_documents.stock_allocation_id')->join('stock_entries', 'stock_entries.stock_document_id', '=', 'stock_documents.id')->where('material_demand_id', $demandId)->where('stock_documents.kind', 'return')->where('quantity_delta', '<', 0)->sum('quantity_delta');
            $this->check($demand->status === 'approved' && $q > 0 && $q + $reserved + $issued + $returned <= $demand->quantity_milli, 'Количество превышает разрешённую потребность.');
            $position = $this->positions($demand->material_id, [$location])[$location];
            $id = DB::table('stock_reservations')->insertGetId(['material_demand_id' => $demandId, 'stock_location_id' => $location, 'remaining_milli' => $q, 'created_at' => now(), 'updated_at' => now()]);
            $doc = $this->document($actor, 'reserve', $reason, ['stock_reservation_id' => $id]);
            $this->entry($doc, $position, 0, $q, 0);
            $this->audit($actor, 'stock.reserved', ['package_id' => $p->id, 'document_id' => $doc], $p->service_request_id);

            return ['id' => $id];
        });
    }

    public function release(User $actor, int $reservationId, string $quantity, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.release', [$reservationId, $quantity, $reason], function () use ($actor, $reservationId, $quantity, $reason) {
            $res = DB::table('stock_reservations')->where('id', $reservationId)->firstOrFail();
            $demand = DB::table('material_demands')->where('id', $res->material_demand_id)->first();
            $p = $this->package($demand->work_package_id, false);
            $this->authorize($actor);
            $this->locations([$res->stock_location_id]);
            $res = DB::table('stock_reservations')->where('id', $reservationId)->lockForUpdate()->first();
            $q = Fixed::quantity($quantity);
            $this->check($q > 0 && $q <= $res->remaining_milli, 'Освобождаемое количество превышает резерв.');
            $position = $this->positions($demand->material_id, [$res->stock_location_id])[$res->stock_location_id];
            DB::table('stock_reservations')->where('id', $res->id)->decrement('remaining_milli', $q);
            $doc = $this->document($actor, 'release', $reason, ['stock_reservation_id' => $res->id]);
            $this->entry($doc, $position, 0, -$q, 0);
            $this->audit($actor, 'stock.released', ['package_id' => $p->id, 'document_id' => $doc], $p->service_request_id);

            return ['id' => $doc];
        });
    }

    public function issue(User $actor, int $reservationId, int $to, string $quantity, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.issue', [$reservationId, $to, $quantity, $reason], function () use ($actor, $reservationId, $to, $quantity, $reason) {
            $res = DB::table('stock_reservations')->where('id', $reservationId)->firstOrFail();
            $demand = DB::table('material_demands')->where('id', $res->material_demand_id)->first();
            $p = $this->package($demand->work_package_id);
            $this->authorize($actor);
            $locations = $this->locations([$res->stock_location_id, $to]);
            $target = $locations[$to];
            $this->check($target->kind === 'technician' && ($p->lead_id === $target->custodian_id || $p->visits()->where('technician_id', $target->custodian_id)->whereIn('status', ['scheduled', 'in_progress'])->exists()), 'Выдача возможна только мастеру этой работы.');
            $res = DB::table('stock_reservations')->where('id', $reservationId)->lockForUpdate()->first();
            $q = Fixed::quantity($quantity);
            $this->check($q > 0 && $q <= $res->remaining_milli && $p->allowsExecution(), 'Количество превышает резерв или работы заблокированы.');
            $positions = $this->positions($demand->material_id, [$res->stock_location_id, $to]);
            $source = $positions[$res->stock_location_id];
            $value = Fixed::ratio($source->value_micro, $q, $source->quantity_milli);
            $allocation = DB::table('stock_allocations')->insertGetId(['material_demand_id' => $demand->id, 'stock_location_id' => $to, 'issued_milli' => $q, 'remaining_milli' => $q, 'remaining_value_micro' => $value, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('stock_reservations')->where('id', $res->id)->decrement('remaining_milli', $q);
            $doc = $this->document($actor, 'issue', $reason, ['stock_reservation_id' => $res->id, 'stock_allocation_id' => $allocation]);
            $this->entry($doc, $source, -$q, -$q, -$value);
            $this->entry($doc, $positions[$to], $q, 0, $value);
            $this->audit($actor, 'stock.issued', ['package_id' => $p->id, 'document_id' => $doc], $p->service_request_id);

            return ['id' => $allocation];
        });
    }

    public function returnUnused(User $actor, int $allocationId, int $to, string $quantity, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.return', [$allocationId, $to, $quantity, $reason], function () use ($actor, $allocationId, $to, $quantity, $reason) {
            $a = DB::table('stock_allocations')->where('id', $allocationId)->firstOrFail();
            $demand = DB::table('material_demands')->where('id', $a->material_demand_id)->first();
            $p = $this->package($demand->work_package_id, false);
            $this->authorize($actor);
            $locations = $this->locations([$a->stock_location_id, $to]);
            $this->check($locations[$to]->kind === 'warehouse', 'Возврат производится на склад.');
            $a = DB::table('stock_allocations')->where('id', $allocationId)->lockForUpdate()->first();
            $q = Fixed::quantity($quantity);
            $this->check($q > 0 && $q <= $a->remaining_milli, 'Израсходованные материалы нельзя вернуть как неиспользованные.');
            $value = Fixed::ratio($a->remaining_value_micro, $q, $a->remaining_milli);
            $positions = $this->positions($demand->material_id, [$a->stock_location_id, $to]);
            DB::table('stock_allocations')->where('id', $a->id)->update(['remaining_milli' => $a->remaining_milli - $q, 'remaining_value_micro' => $a->remaining_value_micro - $value]);
            $doc = $this->document($actor, 'return', $reason, ['stock_allocation_id' => $a->id]);
            $this->entry($doc, $positions[$a->stock_location_id], -$q, 0, -$value);
            $this->entry($doc, $positions[$to], $q, 0, $value);
            $this->audit($actor, 'stock.returned', ['package_id' => $p->id, 'document_id' => $doc], $p->service_request_id);

            return ['id' => $doc];
        });
    }

    public function consumeLocked(User $actor, Visit $visit, array $usage): array
    {
        $this->lockStock();
        abort_unless($actor->fresh()?->hasRole('technician'), 403);
        $p = $visit->package;
        $ids = array_map('intval', array_keys($usage));
        sort($ids);
        $this->check(count(array_unique($ids)) === count($ids), 'Повтор материала в отчёте.');
        $allocations = DB::table('stock_allocations')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $this->check($allocations->count() === count($ids), 'Список выданных материалов изменился.');
        $documents = [];
        // The stock mutex serializes cross-package postings; rows are ordered consistently.
        $prepared = [];
        foreach ($allocations as $a) {
            $d = DB::table('material_demands')->where('id', $a->material_demand_id)->first();
            $location = DB::table('stock_locations')->where('id', $a->stock_location_id)->first();
            $this->check($d->work_package_id === $p->id && $d->status === 'approved' && $location->custodian_id === $actor->id && $visit->technician_id === $actor->id && $visit->status === 'in_progress', 'Нельзя списать чужие или неразрешённые материалы.');
            $prepared[] = [$a, $d];
        }
        usort($prepared, fn ($x, $y) => [$x[0]->stock_location_id, $x[1]->material_id, $x[0]->id] <=> [$y[0]->stock_location_id, $y[1]->material_id, $y[0]->id]);
        foreach ($prepared as [$a, $d]) {
            $q = Fixed::quantity((string) $usage[$a->id]);
            $this->check($q <= $a->remaining_milli, 'Расход превышает выданное количество.');
            if ($q === 0) {
                continue;
            }
            $value = Fixed::ratio($a->remaining_value_micro, $q, $a->remaining_milli);
            $position = $this->positions($d->material_id, [$a->stock_location_id])[$a->stock_location_id];
            $doc = $this->document($actor, 'consume', 'Подтверждённый расход по отчёту выезда', ['visit_id' => $visit->id, 'stock_allocation_id' => $a->id]);
            DB::table('stock_allocations')->where('id', $a->id)->update(['remaining_milli' => $a->remaining_milli - $q, 'remaining_value_micro' => $a->remaining_value_micro - $value]);
            $this->entry($doc, $position, -$q, 0, -$value);
            $documents[] = $doc;
        }

        return $documents;
    }

    public function receiptLocked(User $actor, object $line, object $order, int $quantity, string $reason): int
    {
        $this->lockStock();
        $this->authorize($actor);
        $location = $this->locations([$order->stock_location_id])[$order->stock_location_id];
        $this->check($location->kind === 'warehouse' && $line->purchase_order_id === $order->id && $quantity > 0 && $quantity <= $line->quantity_milli - $line->received_milli, 'Неверное поступление по заказу.');
        $position = $this->positions($line->material_id, [$location->id])[$location->id];
        $doc = $this->document($actor, 'receipt', $reason, ['purchase_line_id' => $line->id]);
        $this->entry($doc, $position, $quantity, 0, Fixed::ratio($line->unit_cost_micro, $quantity, 1000));

        return $doc;
    }

    public function reassignCustodian(User $actor, int $locationId, int $custodianId, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.custodian', [$locationId, $custodianId, $reason], function () use ($actor, $locationId, $custodianId, $reason) {
            $this->authorize($actor);
            $location = DB::table('stock_locations')->where('id', $locationId)->firstOrFail();
            $users = User::whereIn('id', array_filter([$location->custodian_id, $custodianId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->check($location->kind === 'technician' && ($users[$custodianId]?->hasRole('technician') ?? false), 'Выберите действующего мастера.');
            $this->check(! DB::table('stock_allocations')->where('stock_location_id', $locationId)->where('remaining_milli', '>', 0)->exists(), 'Сначала верните материалы, выданные на работы.');
            $this->check(! DB::table('stock_positions')->where('stock_location_id', $locationId)->where('reserved_milli', '>', 0)->exists(), 'Сначала освободите резерв.');
            DB::table('stock_locations')->where('id', $locationId)->update(['custodian_id' => $custodianId, 'updated_at' => now()]);
            $this->audit($actor, 'stock.custodian_changed', ['location_id' => $locationId, 'before' => $location->custodian_id, 'after' => $custodianId, 'reason' => $this->reason($reason)]);

            return ['id' => $locationId];
        });
    }

    /** @return array{ready: bool, fingerprint: string, missing: array} */
    public function readiness(WorkPackage $package): array
    {
        $demands = DB::table('material_demands')->join('materials', 'materials.id', '=', 'material_demands.material_id')
            ->where('work_package_id', $package->id)->where('material_demands.status', 'approved')->orderBy('material_demands.id')
            ->get(['material_demands.id', 'material_id', 'quantity_milli', 'materials.name', 'materials.unit']);
        $team = $package->visits()->where('required', true)->whereIn('status', ['scheduled', 'in_progress'])->pluck('technician_id')->push($package->lead_id)->unique()->all();
        $missing = [];
        foreach ($demands as $demand) {
            $held = (int) DB::table('stock_allocations')->join('stock_locations', 'stock_locations.id', '=', 'stock_allocations.stock_location_id')
                ->join('users', 'users.id', '=', 'stock_locations.custodian_id')
                ->where('material_demand_id', $demand->id)->where('stock_locations.active', true)->where('users.active', true)->whereIn('custodian_id', $team)->sum('remaining_milli');
            $consumed = -(int) DB::table('stock_documents')->join('stock_allocations', 'stock_allocations.id', '=', 'stock_documents.stock_allocation_id')
                ->join('stock_entries', 'stock_entries.stock_document_id', '=', 'stock_documents.id')
                ->where('material_demand_id', $demand->id)->where('stock_documents.kind', 'consume')->sum('quantity_delta');
            if ($held + $consumed < $demand->quantity_milli) {
                $missing[] = ['name' => $demand->name, 'unit' => $demand->unit, 'quantity_milli' => $demand->quantity_milli - $held - $consumed];
            }
        }

        return ['ready' => $missing === [], 'missing' => $missing, 'fingerprint' => hash('sha256', json_encode([$package->execution_round, $demands->map(fn ($d) => [$d->id, $d->material_id, $d->quantity_milli])->all()], JSON_THROW_ON_ERROR))];
    }

    public function usageEvidence(Visit $visit): array
    {
        return DB::table('stock_documents')->join('stock_entries', 'stock_entries.stock_document_id', '=', 'stock_documents.id')
            ->join('materials', 'materials.id', '=', 'stock_entries.material_id')
            ->where('stock_documents.visit_id', $visit->id)->where('stock_documents.kind', 'consume')
            ->orderBy('stock_documents.id')->get(['stock_documents.id as document_id', 'materials.name', 'materials.unit', 'stock_entries.quantity_delta'])
            ->map(fn ($row) => ['document_id' => $row->document_id, 'name' => $row->name, 'unit' => $row->unit, 'quantity' => Fixed::display(-$row->quantity_delta)])->all();
    }

    public function count(User $actor, int $material, int $location, string $actual, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.count', [$material, $location, $actual, $reason], function () use ($actor, $material, $location, $actual, $reason) {
            $this->authorize($actor);
            $this->locations([$location]);
            $p = $this->positions($material, [$location])[$location];
            $q = Fixed::quantity($actual);
            $this->check(! DB::table('stock_allocations')->join('material_demands', 'material_demands.id', '=', 'stock_allocations.material_demand_id')->where('stock_location_id', $location)->where('material_id', $material)->where('remaining_milli', '>', 0)->exists(), 'Сначала разберите выданные на работы материалы.');
            $this->check($q <= $p->quantity_milli && $q >= $p->reserved_milli, 'Недостачу нельзя списать из резерва; излишек оформите поступлением с основанием стоимости.');
            $removed = $p->quantity_milli - $q;
            $value = $removed ? Fixed::ratio($p->value_micro, $removed, $p->quantity_milli) : 0;
            $doc = $this->document($actor, 'count', $reason);
            $this->entry($doc, $p, -$removed, 0, -$value);
            $this->audit($actor, 'stock.counted', ['document_id' => $doc]);

            return ['id' => $doc];
        });
    }

    public function reverse(User $actor, int $documentId, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.reverse', [$documentId, $reason], function () use ($actor, $documentId, $reason) {
            $this->authorize($actor);
            $doc = DB::table('stock_documents')->where('id', $documentId)->firstOrFail();
            $this->check(in_array($doc->kind, ['opening', 'count', 'receipt']) && ! DB::table('stock_documents')->where('reversal_of_id', $documentId)->exists(), 'Этот документ нельзя сторнировать; выдачу исправьте возвратом.');
            if ($doc->kind === 'receipt') {
                $line = DB::table('purchase_lines')->where('id', $doc->purchase_line_id)->firstOrFail();
                $order = DB::table('purchase_orders')->where('id', $line->purchase_order_id)->lockForUpdate()->firstOrFail();
                $received = (int) DB::table('stock_entries')->where('stock_document_id', $documentId)->sum('quantity_delta');
                DB::table('purchase_lines')->where('id', $line->id)->decrement('received_milli', $received);
                DB::table('purchase_orders')->where('id', $order->id)->update(['version' => $order->version + 1, 'status' => $order->status === 'cancelled' ? 'cancelled' : 'partially_received']);
            }
            $entries = DB::table('stock_entries')->where('stock_document_id', $documentId)->orderBy('stock_location_id')->orderBy('material_id')->get();
            $this->locations($entries->pluck('stock_location_id')->unique()->all());
            $id = $this->document($actor, 'reversal', $reason, ['reversal_of_id' => $documentId]);
            foreach ($entries as $entry) {
                $p = $this->positions($entry->material_id, [$entry->stock_location_id])[$entry->stock_location_id];
                $held = DB::table('stock_allocations')->join('material_demands', 'material_demands.id', '=', 'stock_allocations.material_demand_id')->where('stock_location_id', $entry->stock_location_id)->where('material_id', $entry->material_id)->sum('remaining_milli');
                $this->check($p->quantity_milli - $entry->quantity_delta >= $p->reserved_milli + $held, 'Сначала освободите резерв и верните выданные материалы.');
                $this->entry($id, $p, -$entry->quantity_delta, -$entry->reserved_delta, -$entry->value_delta);
            }
            $this->audit($actor, 'stock.reversed', ['document_id' => $id, 'reversal_of_id' => $documentId]);

            return ['id' => $id];
        });
    }

    public function rebuild(User $actor, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'stock.rebuild', [$reason], function () use ($actor, $reason) {
            $this->authorize($actor);
            foreach ($this->balancePairs() as $pair) {
                $e = DB::table('stock_entries')->where('stock_location_id', $pair->stock_location_id)->where('material_id', $pair->material_id)->selectRaw('COALESCE(SUM(quantity_delta), 0) as q, COALESCE(SUM(reserved_delta), 0) as r, COALESCE(SUM(value_delta), 0) as v')->first();
                DB::table('stock_positions')->updateOrInsert(['stock_location_id' => $pair->stock_location_id, 'material_id' => $pair->material_id], ['quantity_milli' => $e->q, 'reserved_milli' => $e->r, 'value_micro' => $e->v, 'updated_at' => now()]);
            }
            $this->audit($actor, 'stock.balances_rebuilt', ['reason' => $this->reason($reason)]);

            return ['reconciled' => $this->reconcile()];
        });
    }

    public function shortages(User $actor): Collection
    {
        $this->authorize($actor);
        $requirements = [];
        $demands = DB::table('material_demands')->join('work_packages', 'work_packages.id', '=', 'material_demands.work_package_id')->join('service_requests', 'service_requests.id', '=', 'work_packages.service_request_id')->where('material_demands.status', 'approved')->whereNotIn('work_packages.status', ['accepted', 'cancelled'])->whereNotIn('service_requests.status', ['closed', 'cancelled'])->get(['material_demands.*']);
        foreach ($demands as $demand) {
            $reserved = (int) DB::table('stock_reservations')->where('material_demand_id', $demand->id)->sum('remaining_milli');
            $issued = (int) DB::table('stock_allocations')->where('material_demand_id', $demand->id)->sum('issued_milli');
            $returned = (int) DB::table('stock_documents')->join('stock_allocations', 'stock_allocations.id', '=', 'stock_documents.stock_allocation_id')->join('stock_entries', 'stock_entries.stock_document_id', '=', 'stock_documents.id')->where('material_demand_id', $demand->id)->where('stock_documents.kind', 'return')->where('quantity_delta', '<', 0)->sum('quantity_delta');
            $requirements[$demand->material_id] = ($requirements[$demand->material_id] ?? 0) + max(0, $demand->quantity_milli - $reserved - $issued - $returned);
        }

        return DB::table('materials')->whereIn('id', array_keys($requirements))->orderBy('name')->get()->map(function ($material) use ($requirements) {
            $free = (int) DB::table('stock_positions')->join('stock_locations', 'stock_locations.id', '=', 'stock_positions.stock_location_id')->where('material_id', $material->id)->where('stock_locations.kind', 'warehouse')->where('stock_locations.active', true)->selectRaw('COALESCE(SUM(quantity_milli - reserved_milli), 0) as free')->value('free');
            $ordered = (int) DB::table('purchase_lines')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_lines.purchase_order_id')->where('material_id', $material->id)->whereIn('purchase_orders.status', ['ordered', 'partially_received'])->selectRaw('COALESCE(SUM(quantity_milli - received_milli), 0) as outstanding')->value('outstanding');

            return (object) ['id' => $material->id, 'name' => $material->name, 'unit' => $material->unit, 'required' => $requirements[$material->id], 'free' => $free, 'shortage' => max(0, $requirements[$material->id] - $free), 'ordered' => $ordered];
        })->filter(fn ($row) => $row->shortage > 0)->values();
    }

    private function balancePairs(): Collection
    {
        return DB::table('stock_positions')->select('stock_location_id', 'material_id')->union(DB::table('stock_entries')->select('stock_location_id', 'material_id'))->orderBy('stock_location_id')->orderBy('material_id')->get();
    }

    public function reconcile(): bool
    {
        foreach ($this->balancePairs() as $pair) {
            $p = DB::table('stock_positions')->where('stock_location_id', $pair->stock_location_id)->where('material_id', $pair->material_id)->first();
            if (! $p) {
                return false;
            }
            $e = DB::table('stock_entries')->where('stock_location_id', $p->stock_location_id)->where('material_id', $p->material_id)->selectRaw('COALESCE(SUM(quantity_delta), 0) as q, COALESCE(SUM(reserved_delta), 0) as r, COALESCE(SUM(value_delta), 0) as v')->first();
            if ($p->quantity_milli != $e->q || $p->reserved_milli != $e->r || $p->value_micro != $e->v) {
                return false;
            }
        }

        return true;
    }
}
