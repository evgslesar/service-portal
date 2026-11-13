<?php

namespace App\Actions\Finance;

use App\Actions\Inventory\Inventory;
use App\Actions\Operation;
use App\Actions\ServiceRequests\Workflow;
use App\Models\AcceptanceRound;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkPackage;
use App\Support\DocumentNumber;
use App\Support\Fixed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Finance
{
    public function __construct(private Operation $operations) {}

    public function authorize(User $actor): void
    {
        abort_unless($actor->fresh()?->hasRole('finance'), 403);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['finance' => $message]);
        }
    }

    private function reason(string $value): string
    {
        return Validator::make(['reason' => trim($value)], ['reason' => 'required|string|max:5000'])->validate()['reason'];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function root(int $id): ServiceRequest
    {
        return ServiceRequest::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function header(ServiceRequest $request): array
    {
        $this->check((bool) config('portal.finance.approved') && filled(config('portal.finance.legal_header')) && filled(config('portal.finance.tax_label')), 'Выпуск документов закрыт: бухгалтер должен утвердить реквизиты и налоговые настройки.');

        return ['seller' => config('portal.finance.legal_header'), 'buyer' => DB::table('organizations')->where('id', $request->organization_id)->value('name'), 'currency' => 'RUB', 'tax' => config('portal.finance.tax_label')];
    }

    public function issue(User $actor, int $requestId, int $version, string $mode, array $lines, string $reason, string $key): array
    {
        $this->authorize($actor);
        Validator::make(['mode' => $mode, 'lines' => $lines], ['mode' => 'required|in:independent_packages,consolidated_request', 'lines' => 'required|array|min:1|max:100', 'lines.*.package_id' => 'required|integer', 'lines.*.kind' => 'required|in:labor,material', 'lines.*.material_id' => 'nullable|integer', 'lines.*.description' => 'required|string|max:1000', 'lines.*.quantity' => 'required|string', 'lines.*.rate' => 'required|string'])->validate();

        return $this->operations->run($actor, $key, 'finance.estimate_issue', [$requestId, $version, $mode, $lines, $reason], function () use ($actor, $requestId, $version, $mode, $lines, $reason) {
            $r = $this->root($requestId);
            $this->authorize($actor);
            $this->check($r->version === $version && ! in_array($r->status, ['closed', 'cancelled']), 'Заявка изменена или закрыта.');
            $this->check(! $r->billing_mode || $r->billing_mode === $mode, 'После выпуска сметы способ расчёта не меняется.');
            $header = $this->header($r);
            $snapshots = [];
            $totals = [];
            $seen = [];
            $packages = $r->packages()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            app(Inventory::class)->lockStock();
            foreach ($lines as $line) {
                $p = $packages->get($line['package_id']);
                $this->check($p && $p->kind === 'paid' && $p->status !== 'cancelled', 'Смета включает только незавершённые платные работы этой заявки.');
                $q = Fixed::quantity($line['quantity']);
                $rate = Fixed::money($line['rate']);
                $this->check($q > 0, 'Разрешённое количество должно быть положительным.');
                $material = $line['kind'] === 'material' ? DB::table('materials')->where('id', $line['material_id'] ?? 0)->where('active', true)->first() : null;
                $this->check($line['kind'] !== 'material' || $material !== null, 'Материал неактивен или отсутствует.');
                $this->check(trim($line['description']) !== '', 'Добавьте описание строки для клиента.');
                $identity = $p->id.':'.$line['kind'].':'.($material?->id ?? 0);
                $this->check(! isset($seen[$identity]), 'Объедините повторные строки одной работы и материала.');
                $seen[$identity] = true;
                $total = Fixed::ratio($q, $rate, 1000);
                $snapshots[] = ['package_id' => $p->id, 'contract_id' => $p->contract_id, 'kind' => $line['kind'], 'material_id' => $material?->id, 'description' => trim($line['description']), 'unit' => $material?->unit ?? 'мин.', 'quantity_milli' => $q, 'rate_kopecks' => $rate, 'total_kopecks' => $total, 'tax' => $header['tax']];
                $totals[$p->id] = ($totals[$p->id] ?? 0) + $total;
            }
            $this->check($mode !== 'independent_packages' || count($totals) === 1, 'Для раздельного расчёта выпускайте смету одной работы.');
            $group = $mode === 'consolidated_request' ? 'r:'.$r->id : 'p:'.array_key_first($totals);
            $previous = DB::table('estimates')->where('group_key', $group)->orderByDesc('revision')->first();
            if ($previous) {
                $this->check(DB::table('estimate_decisions')->where('estimate_id', $previous->id)->exists(), 'Сначала получите решение по выпущенной версии.');
            }
            $total = array_sum($totals);
            $this->check(is_int($total) && $total > 0, 'Смета должна иметь положительную стоимость.');
            $approved = $this->approvedGroup($group);
            $this->validateRevision($approved, $snapshots, $packages);
            $unaccepted = array_filter($totals, fn ($package) => $packages[$package]->status !== 'accepted', ARRAY_FILTER_USE_KEY);
            $shares = $unaccepted ? self::proportional($unaccepted, Fixed::ratio(array_sum($unaccepted), 1, 2)) : [];
            foreach ($totals as $package => $packageTotal) {
                if ($packages[$package]->status === 'accepted') {
                    $shares[$package] = json_decode($approved->shares, true)[$package] ?? 0;
                }
            }
            $id = DB::table('estimates')->insertGetId(['service_request_id' => $r->id, 'actor_id' => $actor->id, 'group_key' => $group, 'revision' => ($previous->revision ?? 0) + 1, 'number' => DocumentNumber::next('EST'), 'header' => $this->json($header), 'lines' => $this->json($snapshots), 'total_kopecks' => $total, 'shares' => $this->json($shares), 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
            $r->update(['billing_mode' => $mode, 'version' => $r->version + 1]);
            $this->operations->event($actor, $r->id, 'finance.estimate_issued');

            return ['id' => $id];
        });
    }

    public static function proportional(array $totals, int $amount): array
    {
        $sum = array_sum($totals);
        $shares = [];
        $remainders = [];
        foreach ($totals as $id => $total) {
            $shares[$id] = Fixed::ratio($total, $amount, $sum, false);
            $remainders[$id] = bcmod(bcmul((string) $total, (string) $amount, 0), (string) $sum, 0);
        }
        $ids = array_keys($totals);
        usort($ids, fn ($a, $b) => bccomp($remainders[$b], $remainders[$a], 0) ?: $a <=> $b);
        $left = $amount - array_sum($shares);
        foreach (array_slice($ids, 0, $left) as $id) {
            $shares[$id]++;
        }
        ksort($shares);

        return $shares;
    }

    public function decideEstimate(User $actor, int $estimateId, string $decision, string $reason, string $key): array
    {
        $fresh = $actor->fresh();
        abort_unless($fresh?->active && $fresh->kind === 'client' && $fresh->accept_work, 403);
        $e = DB::table('estimates')->where('id', $estimateId)->firstOrFail();
        ServiceRequest::visibleTo($fresh)->findOrFail($e->service_request_id);

        return $this->operations->run($actor, $key, 'finance.estimate_decide', [$estimateId, $decision, $reason], function () use ($actor, $e, $decision, $reason) {
            $r = $this->root($e->service_request_id);
            $fresh = $actor->fresh();
            abort_unless($fresh->kind === 'client' && $fresh->accept_work && ServiceRequest::visibleTo($fresh)->whereKey($r->id)->exists(), 403);
            $this->check(in_array($decision, ['approved', 'rejected']) && ! in_array($r->status, ['closed', 'cancelled']), 'Неверное решение или заявка закрыта.');
            $this->check(DB::table('estimates')->where('group_key', $e->group_key)->max('revision') === $e->revision && ! DB::table('estimate_decisions')->where('estimate_id', $e->id)->exists(), 'Версия сметы уже изменилась или рассмотрена.');
            $reason = $decision === 'rejected' ? $this->reason($reason) : trim($reason);
            DB::table('estimate_decisions')->insert(['estimate_id' => $e->id, 'actor_id' => $actor->id, 'decision' => $decision, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            if ($decision === 'approved') {
                $packages = $r->packages()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $approved = $this->approvedGroup($e->group_key, $e->id);
                $this->validateRevision($approved, json_decode($e->lines, true), $packages);
                $shares = $this->reviseAdvancesLocked($actor, $e, $packages);
                $invoice = array_sum($shares) > 0 ? $this->document($e, 'advance', 'estimate:'.$e->id, null, [], $shares, array_sum($shares)) : null;
                app(Inventory::class)->lockStock();
                $this->reviseDemandsLocked($actor, $e, $approved, $packages);
            }
            $this->operations->event($actor, $r->id, 'finance.estimate_'.$decision);

            return ['id' => $e->id, 'invoice_id' => $invoice ?? null];
        });
    }

    private function document(object $estimate, string $kind, string $basis, ?int $package, array $lines, array $shares, int $total): int
    {
        return DB::table('finance_documents')->insertGetId(['service_request_id' => $estimate->service_request_id, 'estimate_id' => $estimate->id, 'work_package_id' => $package, 'basis' => $basis, 'kind' => $kind, 'number' => DocumentNumber::next('INV'), 'header' => $estimate->header, 'lines' => $this->json($lines), 'shares' => $this->json($shares), 'total_kopecks' => $total, 'due_on' => now()->addDays(7)->format('Y-m-d'), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function approvedEstimate(WorkPackage $package): ?object
    {
        return DB::table('estimates')->join('estimate_decisions', 'estimate_decisions.estimate_id', '=', 'estimates.id')->where('service_request_id', $package->service_request_id)->where('decision', 'approved')->orderByDesc('estimates.id')->get(['estimates.*'])->first(fn ($e) => array_key_exists($package->id, json_decode($e->shares, true)));
    }

    public function executionReady(WorkPackage $package): bool
    {
        if ($package->kind !== 'paid') {
            return true;
        }
        $e = $this->approvedEstimate($package);
        if (! $e) {
            return false;
        }

        return $this->fundedAdvance($package) >= (json_decode($e->shares, true)[$package->id] ?? PHP_INT_MAX);
    }

    public function acceptancePreview(WorkPackage $package): array
    {
        $preview = $this->actualPreview($package);
        $funded = $this->fundedAdvance($package);
        $offset = min($funded, $preview['total_kopecks']);

        return ['lines' => $preview['lines'], 'total_kopecks' => $preview['total_kopecks'], 'advance_kopecks' => $offset, 'payable_kopecks' => max(0, $preview['total_kopecks'] - $funded), 'credit_kopecks' => max(0, $funded - $preview['total_kopecks'])];
    }

    public function actualPreview(WorkPackage $package): array
    {
        $e = $this->approvedEstimate($package);
        $this->check($e !== null, 'Нет утверждённой сметы.');
        $lines = [];
        foreach (json_decode($e->lines, true) as $line) {
            if ($line['package_id'] !== $package->id) {
                continue;
            }
            if ($line['kind'] === 'labor') {
                $actual = (int) $package->visits()->whereIn('status', ['completed', 'interrupted'])->where('execution_round', 1)->sum('labor_minutes') * 1000;
            } else {
                $actual = -(int) DB::table('stock_entries')->join('stock_documents', 'stock_documents.id', '=', 'stock_entries.stock_document_id')->join('visits', 'visits.id', '=', 'stock_documents.visit_id')->where('work_package_id', $package->id)->where('execution_round', 1)->where('stock_documents.kind', 'consume')->where('material_id', $line['material_id'])->sum('quantity_delta');
            }
            $q = min($actual, $line['quantity_milli']);
            $lines[] = [...$line, 'quantity_milli' => $q, 'total_kopecks' => Fixed::ratio($q, $line['rate_kopecks'], 1000)];
        }

        return ['estimate' => $e, 'lines' => $lines, 'total_kopecks' => array_sum(array_column($lines, 'total_kopecks'))];
    }

    public function acceptedLocked(User $actor, WorkPackage $package, AcceptanceRound $round): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        if ($package->kind !== 'paid') {
            return;
        }
        $basis = 'accepted-package:'.$package->id;
        if (DB::table('finance_documents')->where('basis', $basis)->exists()) {
            return;
        }
        $preview = $this->actualPreview($package);
        $id = $this->document($preview['estimate'], 'actual', $basis, $package->id, $preview['lines'], [$package->id => $preview['total_kopecks']], $preview['total_kopecks']);
        $this->offsetsLocked($actor, $id);
        app(Fiscalization::class)->recordLocked($actor, $package->service_request_id, 'actual', $id, 'final_settlement', $preview['total_kopecks'], ['finance_document_id' => $id, 'header' => json_decode($preview['estimate']->header, true), 'lines' => $preview['lines'], 'acceptance_round_id' => $round->id]);
        $this->operations->event($actor, $package->service_request_id, 'finance.invoice_issued', ['package_id' => $package->id]);
    }

    public function allocated(int $document, ?int $package = null): int
    {
        return (int) DB::table('payment_allocations')->where('finance_document_id', $document)->when($package !== null, fn ($q) => $q->where('work_package_id', $package))->sum('amount_kopecks');
    }

    public function debt(object $document): int
    {
        if (! in_array($document->kind, ['actual', 'advance'], true)) {
            return 0;
        }
        $offset = $document->kind === 'actual' ? (int) DB::table('advance_offsets')->where('actual_document_id', $document->id)->sum('amount_kopecks') : 0;

        return max(0, array_sum($this->effectiveShares($document)) - $this->allocated($document->id) - $offset);
    }

    private function offsetsLocked(User $actor, int $actualId): void
    {
        $actual = DB::table('finance_documents')->where('id', $actualId)->firstOrFail();
        foreach (DB::table('finance_documents')->where('service_request_id', $actual->service_request_id)->where('kind', 'advance')->orderBy('id')->get() as $advance) {
            $used = (int) DB::table('advance_offsets')->where('advance_document_id', $advance->id)->where('work_package_id', $actual->work_package_id)->sum('amount_kopecks');
            $available = min($this->allocated($advance->id, $actual->work_package_id), $this->effectiveShares($advance)[$actual->work_package_id] ?? 0) - $used;
            $amount = min($available, $this->debt($actual));
            if ($amount > 0) {
                $offsetId = DB::table('advance_offsets')->insertGetId(['advance_document_id' => $advance->id, 'actual_document_id' => $actualId, 'work_package_id' => $actual->work_package_id, 'amount_kopecks' => $amount, 'created_at' => now(), 'updated_at' => now()]);
                app(Fiscalization::class)->recordLocked($actor, $actual->service_request_id, 'offset', $offsetId, 'advance_offset', $amount, ['advance_document_id' => $advance->id, 'actual_document_id' => $actualId, 'work_package_id' => $actual->work_package_id, 'header' => json_decode($actual->header, true)]);
            }
        }
    }

    public function recordBank(User $actor, int $requestId, array $input, string $key): array
    {
        $this->authorize($actor);
        $input = Validator::make($input, ['reference' => 'required|string|max:255', 'account' => 'required|string|max:255', 'paid_on' => 'required|date_format:Y-m-d', 'payer' => 'required|string|max:255', 'evidence' => 'required|string|max:5000', 'amount' => 'required|string'])->validate();

        return $this->operations->run($actor, $key, 'finance.bank', [$requestId, $input], function () use ($actor, $requestId, $input) {
            $r = $this->root($requestId);
            $this->authorize($actor);
            $amount = Fixed::money($input['amount']);
            $this->check($amount > 0, 'Сумма должна быть положительной.');
            $reference = trim($input['reference']);
            $account = trim($input['account']);
            $this->reason($input['evidence']);
            $receiptKey = 'bank:'.hash('sha256', $account.'|'.$input['paid_on'].'|'.$reference);
            $this->check(! DB::table('payment_receipts')->where('receipt_key', $receiptKey)->exists(), 'Этот банковский документ уже зарегистрирован.');
            $id = $this->receiptLocked($actor, $r, 'bank', $receiptKey, $reference, $account, $input['paid_on'], trim($input['payer']), $input['evidence'], $amount);
            $this->operations->event($actor, $r->id, 'finance.payment_received');

            return ['id' => $id];
        });
    }

    public function receiptLocked(User $actor, ServiceRequest $r, string $source, string $receiptKey, string $reference, string $account, string $date, string $payer, string $evidence, int $amount): int
    {
        abort_unless(DB::transactionLevel() > 0 && $amount > 0, 500);

        $id = DB::table('payment_receipts')->insertGetId(['service_request_id' => $r->id, 'organization_id' => $r->organization_id, 'actor_id' => $actor->id, 'source' => $source, 'receipt_key' => $receiptKey, 'reference' => $reference, 'account' => $account, 'paid_on' => $date, 'payer' => $payer, 'evidence' => $evidence, 'amount_kopecks' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        if ($source === 'bank') {
            app(Fiscalization::class)->recordLocked($actor, $r->id, 'payment', $id, 'bank_transfer', $amount, ['payment_receipt_id' => $id, 'source' => $source, 'payer' => $payer, 'paid_on' => $date]);
        }

        return $id;
    }

    public function allocateProviderReceiptLocked(User $actor, ServiceRequest $request, int $receipt, int $invoiceId, array $intendedShares): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        if (in_array($request->status, ['closed', 'cancelled'], true)) {
            return;
        }
        $invoice = DB::table('finance_documents')->where('id', $invoiceId)->where('service_request_id', $request->id)->firstOrFail();
        $shares = $this->effectiveShares($invoice);
        foreach ($intendedShares as $package => $intended) {
            if (! WorkPackage::whereKey($package)->where('service_request_id', $request->id)->where('status', '!=', 'cancelled')->exists()) {
                continue;
            }
            $amount = min($intended, max(0, ($shares[$package] ?? 0) - $this->allocated($invoiceId, (int) $package)), $this->debt($invoice));
            if ($amount <= 0) {
                continue;
            }
            DB::table('payment_allocations')->insert(['payment_receipt_id' => $receipt, 'finance_document_id' => $invoiceId, 'work_package_id' => $package, 'actor_id' => $actor->id, 'amount_kopecks' => $amount, 'reason' => 'Подтверждённая тестовая оплата ЮKassa', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('finance_documents')->where('service_request_id', $request->id)->where('kind', 'actual')->get() as $actual) {
            $this->offsetsLocked($actor, $actual->id);
        }
    }

    public function allocate(User $actor, int $receiptId, int $invoiceId, int $packageId, string $amount, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'finance.allocate', [$receiptId, $invoiceId, $packageId, $amount, $reason], function () use ($actor, $receiptId, $invoiceId, $packageId, $amount, $reason) {
            $receipt = DB::table('payment_receipts')->where('id', $receiptId)->firstOrFail();
            $r = $this->root($receipt->service_request_id);
            $this->authorize($actor);
            $invoice = DB::table('finance_documents')->where('id', $invoiceId)->firstOrFail();
            $shares = $this->effectiveShares($invoice);
            $q = Fixed::money($amount);
            $this->check(! DB::table('bank_receipt_reversals')->where('payment_receipt_id', $receiptId)->exists(), 'Это поступление сторнировано.');
            $this->check($invoice->service_request_id === $r->id && isset($shares[$packageId]) && $q > 0, 'Распределение разрешено только внутри этой заявки.');
            $this->check($q <= $receipt->amount_kopecks - (int) DB::table('payment_allocations')->where('payment_receipt_id', $receiptId)->sum('amount_kopecks') - $this->receiptRefunds($receiptId), 'Недостаточно свободных подтверждённых средств.');
            $this->check($q <= $shares[$packageId] - $this->allocated($invoiceId, $packageId) && $q <= $this->debt($invoice), 'Сумма превышает долг по счёту или долю работы.');
            $id = DB::table('payment_allocations')->insertGetId(['payment_receipt_id' => $receiptId, 'finance_document_id' => $invoiceId, 'work_package_id' => $packageId, 'actor_id' => $actor->id, 'amount_kopecks' => $q, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('finance_documents')->where('service_request_id', $r->id)->where('kind', 'actual')->get() as $actual) {
                $this->offsetsLocked($actor, $actual->id);
            }
            app(Workflow::class)->aggregate($r);
            $this->operations->event($actor, $r->id, 'finance.payment_allocated');

            return ['id' => $id];
        });
    }

    public function reverseAllocation(User $actor, int $allocationId, string $amount, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'finance.allocation_reverse', [$allocationId, $amount, $reason], function () use ($actor, $allocationId, $amount, $reason) {
            $allocation = DB::table('payment_allocations')->where('id', $allocationId)->firstOrFail();
            $receipt = DB::table('payment_receipts')->where('id', $allocation->payment_receipt_id)->firstOrFail();
            $r = $this->root($receipt->service_request_id);
            $this->authorize($actor);
            $invoice = DB::table('finance_documents')->where('id', $allocation->finance_document_id)->firstOrFail();
            $q = Fixed::money($amount);
            $remaining = $allocation->amount_kopecks + (int) DB::table('payment_allocations')->where('reversal_of_id', $allocationId)->sum('amount_kopecks');
            $this->check($allocation->reversal_of_id === null && $q > 0 && $q <= $remaining, 'Сумма превышает остаток исходного распределения.');
            if ($invoice->kind === 'advance') {
                $used = (int) DB::table('advance_offsets')->where('advance_document_id', $invoice->id)->where('work_package_id', $allocation->work_package_id)->sum('amount_kopecks');
                $this->check($this->allocated($invoice->id, $allocation->work_package_id) - $q >= $used, 'Зачтённый в принятые работы аванс не перераспределяется.');
            }
            $id = DB::table('payment_allocations')->insertGetId(['payment_receipt_id' => $receipt->id, 'finance_document_id' => $invoice->id, 'work_package_id' => $allocation->work_package_id, 'actor_id' => $actor->id, 'amount_kopecks' => -$q, 'reversal_of_id' => $allocationId, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
            app(Workflow::class)->aggregate($r);
            $this->operations->event($actor, $r->id, 'finance.allocation_reversed');

            return ['id' => $id];
        });
    }

    private function approvedGroup(string $group, ?int $except = null): ?object
    {
        return DB::table('estimates')->join('estimate_decisions', 'estimate_decisions.estimate_id', '=', 'estimates.id')->where('group_key', $group)->where('decision', 'approved')->when($except, fn ($query) => $query->where('estimates.id', '!=', $except))->orderByDesc('revision')->first(['estimates.*']);
    }

    private function lineIdentity(array $line): string
    {
        return $line['package_id'].':'.$line['kind'].':'.($line['material_id'] ?? 0);
    }

    private function validateRevision(?object $approved, array $lines, Collection $packages): void
    {
        $new = collect($lines)->keyBy(fn ($line) => $this->lineIdentity($line));
        $old = collect($approved ? json_decode($approved->lines, true) : [])->keyBy(fn ($line) => $this->lineIdentity($line));
        foreach ($lines as $line) {
            $package = $packages->get($line['package_id']);
            $this->check($package !== null && $package->status !== 'cancelled', 'Работы уже изменились или отменены.');
            if ($package->status === 'accepted') {
                $this->check($old->get($this->lineIdentity($line)) === $line, 'Принятые работы не пересматриваются сметой.');
            }
        }
        foreach ($old as $identity => $line) {
            $package = $packages[$line['package_id']];
            $replacement = $new->get($identity);
            if ($package->status === 'cancelled') {
                continue;
            }
            $this->check($new->contains(fn ($entry) => $entry['package_id'] === $package->id), 'Не удаляйте целиком утверждённую работу; отмените её отдельно.');
            if ($package->status === 'accepted') {
                $this->check($replacement === $line, 'Принятые работы не пересматриваются сметой.');

                continue;
            }
            $used = $line['kind'] === 'labor'
                ? (int) $package->visits()->whereIn('status', ['completed', 'interrupted'])->where('execution_round', 1)->sum('labor_minutes') * 1000
                : -(int) DB::table('stock_entries')->join('stock_documents', 'stock_documents.id', '=', 'stock_entries.stock_document_id')->join('visits', 'visits.id', '=', 'stock_documents.visit_id')->where('work_package_id', $package->id)->where('execution_round', 1)->where('stock_documents.kind', 'consume')->where('material_id', $line['material_id'])->sum('quantity_delta');
            $this->check($used === 0 || ($replacement && $replacement['quantity_milli'] >= min($used, $line['quantity_milli']) && $replacement['rate_kopecks'] === $line['rate_kopecks']), 'Нельзя уменьшить выполненный объём или изменить его утверждённую расценку.');
        }
    }

    public function effectiveShares(object $document): array
    {
        $shares = json_decode($document->shares, true);
        foreach (DB::table('finance_corrections')->join('finance_documents', 'finance_documents.id', '=', 'finance_corrections.credit_document_id')->where('original_document_id', $document->id)->get(['finance_documents.shares']) as $credit) {
            foreach (json_decode($credit->shares, true) as $package => $amount) {
                $shares[$package] = ($shares[$package] ?? 0) + $amount;
            }
        }

        return $shares;
    }

    private function fundedAdvance(WorkPackage $package): int
    {
        return (int) DB::table('finance_documents')->where('service_request_id', $package->service_request_id)->where('kind', 'advance')->get()->sum(fn ($document) => min($this->allocated($document->id, $package->id), $this->effectiveShares($document)[$package->id] ?? 0));
    }

    private function appendCredit(User $actor, object $original, object $estimate, array $reductions, string $reason): int
    {
        $shares = array_map(fn ($value) => -$value, $reductions);
        $id = $this->document($estimate, $original->kind.'_credit', 'credit:'.(string) Str::uuid(), $original->work_package_id, [], $shares, array_sum($shares));
        DB::table('finance_corrections')->insert(['original_document_id' => $original->id, 'credit_document_id' => $id, 'actor_id' => $actor->id, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
        app(Fiscalization::class)->recordLocked($actor, $original->service_request_id, 'credit', $id, 'correction', array_sum($shares), ['original_document_id' => $original->id, 'credit_document_id' => $id, 'shares' => $shares, 'header' => json_decode($estimate->header, true)]);

        return $id;
    }

    private function reviseAdvancesLocked(User $actor, object $estimate, Collection $packages): array
    {
        $targets = array_filter(json_decode($estimate->shares, true), fn ($package) => $packages[$package]->status !== 'accepted', ARRAY_FILTER_USE_KEY);
        $advances = DB::table('finance_documents')->join('estimates', 'estimates.id', '=', 'finance_documents.estimate_id')->where('estimates.group_key', $estimate->group_key)->where('finance_documents.kind', 'advance')->orderBy('finance_documents.id')->get(['finance_documents.*']);
        $current = [];
        foreach ($advances as $advance) {
            foreach ($this->effectiveShares($advance) as $package => $amount) {
                $current[$package] = ($current[$package] ?? 0) + $amount;
            }
        }
        foreach ($current as $package => $amount) {
            if ($packages[$package]->status === 'cancelled') {
                $targets[$package] = 0;
            }
        }
        $shortfalls = [];
        foreach ($targets as $package => $target) {
            $shortfalls[$package] = max(0, $target - ($current[$package] ?? 0));
            $excess = max(0, ($current[$package] ?? 0) - $target);
            foreach ($advances as $advance) {
                $reduce = min($excess, $this->effectiveShares($advance)[$package] ?? 0);
                if ($reduce > 0) {
                    $this->appendCredit($actor, $advance, $estimate, [$package => $reduce], 'Изменённая смета '.$estimate->number.': '.$estimate->reason);
                    $excess -= $reduce;
                }
            }
        }

        return array_filter($shortfalls, fn ($amount) => $amount > 0);
    }

    private function reviseDemandsLocked(User $actor, object $estimate, ?object $approved, Collection $packages): void
    {
        $targets = [];
        foreach (array_merge($approved ? json_decode($approved->lines, true) : [], json_decode($estimate->lines, true)) as $line) {
            if ($line['kind'] === 'material' && ! in_array($packages[$line['package_id']]->status, ['accepted', 'cancelled'])) {
                $targets[$this->lineIdentity($line)] = [$line['package_id'], $line['material_id'], 0];
            }
        }
        foreach (json_decode($estimate->lines, true) as $line) {
            if (isset($targets[$this->lineIdentity($line)])) {
                $targets[$this->lineIdentity($line)][2] = $line['quantity_milli'];
            }
        }
        foreach ($targets as [$package, $material, $target]) {
            $demands = DB::table('material_demands')->where('work_package_id', $package)->where('material_id', $material)->where('billable', true)->where('status', 'approved')->orderByDesc('id')->lockForUpdate()->get();
            $difference = $target - (int) $demands->sum('quantity_milli');
            if ($difference > 0) {
                DB::table('material_demands')->insert(['work_package_id' => $package, 'material_id' => $material, 'actor_id' => $actor->id, 'quantity_milli' => $difference, 'billable' => true, 'reason' => 'Утверждённая смета '.$estimate->number, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($difference < 0) {
                $remove = -$difference;
                foreach ($demands as $demand) {
                    $held = (int) DB::table('stock_reservations')->where('material_demand_id', $demand->id)->sum('remaining_milli') + (int) DB::table('stock_allocations')->where('material_demand_id', $demand->id)->sum('issued_milli');
                    $held += (int) DB::table('stock_documents')->join('stock_allocations', 'stock_allocations.id', '=', 'stock_documents.stock_allocation_id')->join('stock_entries', 'stock_entries.stock_document_id', '=', 'stock_documents.id')->where('material_demand_id', $demand->id)->where('stock_documents.kind', 'return')->where('quantity_delta', '<', 0)->sum('quantity_delta');
                    $reduce = min($remove, max(0, $demand->quantity_milli - $held));
                    DB::table('material_demands')->where('id', $demand->id)->update(['quantity_milli' => $demand->quantity_milli - $reduce, 'status' => $demand->quantity_milli === $reduce ? 'cancelled' : 'approved', 'updated_at' => now()]);
                    $remove -= $reduce;
                }
                $this->check($remove === 0, 'Сначала освободите резерв и верните материалы сверх новой потребности.');
            }
        }
    }

    public function reverseBankReceipt(User $actor, int $receiptId, string $reason, string $evidence, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'finance.bank_reverse', [$receiptId, $reason, $evidence], function () use ($actor, $receiptId, $reason, $evidence) {
            $receipt = DB::table('payment_receipts')->where('id', $receiptId)->firstOrFail();
            $request = $this->root($receipt->service_request_id);
            $this->authorize($actor);
            $this->check($receipt->source === 'bank' && ! DB::table('bank_receipt_reversals')->where('payment_receipt_id', $receiptId)->exists(), 'Сторнируется только исходное банковское поступление.');
            $this->check((int) DB::table('payment_allocations')->where('payment_receipt_id', $receiptId)->sum('amount_kopecks') === 0 && $this->receiptRefunds($receiptId) === 0, 'Сначала отмените распределения; поступление с возвратом не сторнируется.');
            $id = DB::table('bank_receipt_reversals')->insertGetId(['payment_receipt_id' => $receiptId, 'actor_id' => $actor->id, 'amount_kopecks' => $receipt->amount_kopecks, 'reason' => $this->reason($reason), 'evidence' => $this->reason($evidence), 'created_at' => now(), 'updated_at' => now()]);
            app(Fiscalization::class)->recordLocked($actor, $request->id, 'bank_reversal', $id, 'correction', -$receipt->amount_kopecks, ['bank_receipt_reversal_id' => $id, 'payment_receipt_id' => $receiptId, 'source' => 'bank']);
            $this->operations->event($actor, $request->id, 'finance.receipt_reversed');
            app(Workflow::class)->aggregate($request);

            return ['id' => $id];
        });
    }

    public function creditActual(User $actor, int $invoiceId, string $amount, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'finance.actual_credit', [$invoiceId, $amount, $reason], function () use ($actor, $invoiceId, $amount, $reason) {
            $invoice = DB::table('finance_documents')->where('id', $invoiceId)->firstOrFail();
            $request = $this->root($invoice->service_request_id);
            $this->authorize($actor);
            $q = Fixed::money($amount);
            $this->check($invoice->kind === 'actual' && $q > 0 && $q <= array_sum($this->effectiveShares($invoice)), 'Кредит не может превышать неисправленную сумму счёта за принятые работы.');
            $estimate = DB::table('estimates')->where('id', $invoice->estimate_id)->firstOrFail();
            $id = $this->appendCredit($actor, $invoice, $estimate, [$invoice->work_package_id => $q], $reason);
            $this->unwindCreditLocked($actor, $invoice, $reason);
            $this->operations->event($actor, $request->id, 'finance.invoice_credited');
            app(Workflow::class)->aggregate($request);

            return ['id' => $id];
        });
    }

    private function unwindCreditLocked(User $actor, object $invoice, string $reason): void
    {
        $offset = (int) DB::table('advance_offsets')->where('actual_document_id', $invoice->id)->sum('amount_kopecks');
        $excess = max(0, $this->allocated($invoice->id) + $offset - array_sum($this->effectiveShares($invoice)));
        foreach (DB::table('payment_allocations')->where('finance_document_id', $invoice->id)->whereNull('reversal_of_id')->orderByDesc('id')->get() as $allocation) {
            $remaining = $allocation->amount_kopecks + (int) DB::table('payment_allocations')->where('reversal_of_id', $allocation->id)->sum('amount_kopecks');
            $reduce = min($remaining, $excess);
            if ($reduce > 0) {
                DB::table('payment_allocations')->insert(['payment_receipt_id' => $allocation->payment_receipt_id, 'finance_document_id' => $invoice->id, 'work_package_id' => $allocation->work_package_id, 'actor_id' => $actor->id, 'amount_kopecks' => -$reduce, 'reversal_of_id' => $allocation->id, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
                $excess -= $reduce;
            }
        }
        foreach (DB::table('advance_offsets')->where('actual_document_id', $invoice->id)->where('amount_kopecks', '>', 0)->orderByDesc('id')->get() as $original) {
            $reversed = (int) DB::table('advance_offset_reversals')->join('advance_offsets', 'advance_offsets.id', '=', 'advance_offset_reversals.reversal_offset_id')->where('original_offset_id', $original->id)->sum('advance_offsets.amount_kopecks');
            $reduce = min($excess, $original->amount_kopecks + $reversed);
            if ($reduce > 0) {
                $reversal = DB::table('advance_offsets')->insertGetId(['advance_document_id' => $original->advance_document_id, 'actual_document_id' => $invoice->id, 'work_package_id' => $original->work_package_id, 'amount_kopecks' => -$reduce, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('advance_offset_reversals')->insert(['original_offset_id' => $original->id, 'reversal_offset_id' => $reversal, 'actor_id' => $actor->id, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
                app(Fiscalization::class)->recordLocked($actor, $invoice->service_request_id, 'offset', $reversal, 'correction', -$reduce, ['original_offset_id' => $original->id, 'reversal_offset_id' => $reversal, 'actual_document_id' => $invoice->id]);
                $excess -= $reduce;
            }
        }
        $this->check($excess === 0, 'Не удалось согласовать кредит с распределениями.');
    }

    private function receiptRefunds(int $id): int
    {
        return (int) DB::table('refunds')->where('payment_receipt_id', $id)->whereNotIn('status', ['cancelled'])->sum('amount_kopecks');
    }

    public function balance(int $requestId): array
    {
        $reversed = (int) DB::table('bank_receipt_reversals')->join('payment_receipts', 'payment_receipts.id', '=', 'bank_receipt_reversals.payment_receipt_id')->where('service_request_id', $requestId)->sum('bank_receipt_reversals.amount_kopecks');
        $received = (int) DB::table('payment_receipts')->where('service_request_id', $requestId)->sum('amount_kopecks') - $reversed;
        $charged = (int) DB::table('finance_documents')->where('service_request_id', $requestId)->whereIn('kind', ['actual', 'actual_credit'])->sum('total_kopecks');
        $refunds = DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('service_request_id', $requestId);
        $returned = (int) (clone $refunds)->where('refunds.status', 'succeeded')->sum('refunds.amount_kopecks');
        $pending = (clone $refunds)->whereNotIn('refunds.status', ['succeeded', 'cancelled'])->exists();
        $debt = DB::table('finance_documents')->where('service_request_id', $requestId)->where('kind', 'actual')->get()->sum(fn ($i) => $this->debt($i));

        return ['received' => $received, 'charged' => $charged, 'returned' => $returned, 'credit' => max(0, $received - $charged - $returned), 'debt' => $debt, 'pending_refund' => $pending];
    }

    public function settled(int $requestId): bool
    {
        $b = $this->balance($requestId);
        $unresolved = DB::table('provider_attempts')->where('service_request_id', $requestId)->whereNotIn('status', ['succeeded', 'cancelled'])->exists();

        return $b['debt'] === 0 && $b['credit'] === 0 && ! $b['pending_refund'] && ! $unresolved;
    }

    public function approveRefund(User $actor, int $receiptId, string $amount, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'finance.refund', [$receiptId, $amount, $reason], function () use ($actor, $receiptId, $amount, $reason) {
            $receipt = DB::table('payment_receipts')->where('id', $receiptId)->firstOrFail();
            $r = $this->root($receipt->service_request_id);
            $this->authorize($actor);
            $this->check(! $r->packages()->whereNotIn('status', ['accepted', 'cancelled'])->exists(), 'Сначала разрешите фактический объём и отменённые работы.');
            $this->check(! DB::table('bank_receipt_reversals')->where('payment_receipt_id', $receiptId)->exists(), 'Это поступление сторнировано.');
            $q = Fixed::money($amount);
            $b = $this->balance($r->id);
            $pending = (int) DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('service_request_id', $r->id)->whereNotIn('refunds.status', ['succeeded', 'cancelled'])->sum('refunds.amount_kopecks');
            $this->check($q > 0 && $q <= $b['credit'] - $pending && $q <= $receipt->amount_kopecks - $this->receiptRefunds($receiptId) - (int) DB::table('payment_allocations')->where('payment_receipt_id', $receiptId)->sum('amount_kopecks'), 'Возврат превышает свободный остаток поступления. Сначала отмените распределение неиспользованного аванса.');
            $id = DB::table('refunds')->insertGetId(['payment_receipt_id' => $receiptId, 'actor_id' => $actor->id, 'amount_kopecks' => $q, 'reason' => $this->reason($reason), 'created_at' => now(), 'updated_at' => now()]);
            $this->operations->event($actor, $r->id, 'finance.refund_approved');

            return ['id' => $id];
        });
    }

    public function confirmBankRefund(User $actor, int $refundId, string $reference, string $evidence, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'finance.bank_refund_confirm', [$refundId, $reference, $evidence], function () use ($actor, $refundId, $reference, $evidence) {
            $refund = DB::table('refunds')->where('id', $refundId)->firstOrFail();
            $receipt = DB::table('payment_receipts')->where('id', $refund->payment_receipt_id)->firstOrFail();
            $r = $this->root($receipt->service_request_id);
            $this->authorize($actor);
            $this->check($receipt->source === 'bank' && $refund->status === 'approved', 'Онлайн-возврат подтверждается только платёжным провайдером.');
            $this->confirmedRefundLocked($actor, $refund, $reference, $evidence);
            app(Workflow::class)->aggregate($r);
            $this->operations->event($actor, $r->id, 'finance.refund_succeeded');

            return ['id' => $refundId];
        });
    }

    public function confirmedRefundLocked(User $actor, object $refund, string $reference, string $evidence): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        DB::table('refund_confirmations')->insert(['refund_id' => $refund->id, 'actor_id' => $actor->id, 'reference' => $this->reason($reference), 'evidence' => $this->reason($evidence), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('refunds')->where('id', $refund->id)->update(['status' => 'succeeded', 'updated_at' => now()]);
        $receipt = DB::table('payment_receipts')->where('id', $refund->payment_receipt_id)->firstOrFail();
        app(Fiscalization::class)->recordLocked($actor, $receipt->service_request_id, 'refund', $refund->id, 'refund', -$refund->amount_kopecks, ['refund_id' => $refund->id, 'payment_receipt_id' => $receipt->id, 'source' => $receipt->source, 'reference' => trim($reference)]);
    }
}
