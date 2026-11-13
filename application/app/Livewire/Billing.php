<?php

namespace App\Livewire;

use App\Actions\Finance\Finance;
use App\Actions\Finance\Fiscalization;
use App\Actions\Finance\ProviderCommands;
use App\Integrations\YooKassa;
use App\Models\ServiceRequest;
use App\Support\Fixed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Billing extends TaskPage
{
    #[Locked]
    public int $id;

    #[Locked]
    public int $version;

    public string $mode = 'independent_packages';

    public array $lines = [['package_id' => '', 'kind' => 'labor', 'material_id' => null, 'description' => '', 'quantity' => '', 'rate' => '']];

    public string $reason = '';

    public array $bank = ['reference' => '', 'account' => '', 'paid_on' => '', 'payer' => '', 'amount' => '', 'evidence' => ''];

    public ?int $receipt = null;

    public ?int $invoice = null;

    public ?int $package = null;

    public string $amount = '';

    public ?int $refund = null;

    public ?int $allocation = null;

    public string $reference = '';

    public array $providerCandidates = [];

    public array $fiscalReasons = [];

    public string $evidence = '';

    private function record(): ServiceRequest
    {
        $u = $this->actor()->fresh();
        abort_unless($u->hasRole('finance') || ($u->kind === 'client' && $u->accept_work), 403);

        return ServiceRequest::visibleTo($u)->findOrFail($this->id);
    }

    public function mount(int $id): void
    {
        $this->id = $id;
        $r = $this->record();
        $this->version = $r->version;
        $this->mode = $r->billing_mode ?? $this->mode;
        $this->bank['paid_on'] = now()->format('Y-m-d');
        $this->prepareKeys(['issue', 'bank', 'allocate', 'reverse', 'refund', 'confirm', 'bank-reverse', 'credit']);
        $this->prepareKeys(DB::table('finance_documents')->where('service_request_id', $id)->pluck('id')->map(fn ($invoice) => 'checkout-'.$invoice)->all());
        $this->prepareKeys(DB::table('provider_attempts')->where('service_request_id', $id)->pluck('id')->flatMap(fn ($attempt) => ['reconcile-'.$attempt, 'bind-'.$attempt])->all());
        $this->prepareKeys(DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('service_request_id', $id)->pluck('refunds.id')->map(fn ($refund) => 'dispatch-'.$refund)->all());
        if ($this->actor()->fresh()->hasRole('finance')) {
            $this->prepareKeys(DB::table('fiscal_receipt_records')->where('service_request_id', $id)->pluck('id')->map(fn ($record) => 'fiscal-'.$record)->all());
        }
        foreach (DB::table('estimates')->where('service_request_id', $id)->get() as $e) {
            $this->prepareKeys(['decision-'.$e->id]);
        }
    }

    public function addLine(): void
    {
        $this->record();
        abort_unless($this->actor()->hasRole('finance') && count($this->lines) < 100, 403);
        $this->lines[] = ['package_id' => '', 'kind' => 'labor', 'material_id' => null, 'description' => '', 'quantity' => '', 'rate' => ''];
    }

    public function useCatalogRate(int $index): void
    {
        $this->record();
        abort_unless($this->actor()->fresh()->hasRole('finance'), 403);
        abort_unless(isset($this->lines[$index]) && ($this->lines[$index]['kind'] ?? null) === 'material', 422);
        $material = DB::table('materials')->where('id', $this->lines[$index]['material_id'] ?? null)->where('active', true)->whereNotNull('default_rate_kopecks')->first();
        if (! $material) {
            throw ValidationException::withMessages(['lines.'.$index.'.rate' => 'Выберите действующий материал с типовой ценой или укажите цену вручную.']);
        }
        $this->lines[$index]['rate'] = Fixed::display($material->default_rate_kopecks, 2);
        $this->resetValidation('lines.'.$index.'.rate');
    }

    public function refreshVersion(): void
    {
        $this->version = $this->record()->version;
        $this->rotateKey('issue');
        $this->resetValidation();
    }

    public function issue(Finance $f): void
    {
        $this->record();
        $f->issue($this->actor(), $this->id, $this->version, $this->mode, $this->lines, $this->reason, $this->key('issue'));
        $this->version = $this->record()->version;
        $this->done('issue', 'Смета выпущена. Ответственный клиент рассмотрит эту версию.');
    }

    public function decide(int $id, string $decision, Finance $f): void
    {
        $this->record();
        abort_unless(DB::table('estimates')->where('id', $id)->where('service_request_id', $this->id)->exists(), 404);
        $f->decideEstimate($this->actor(), $id, $decision, $this->reason, $this->key('decision-'.$id));
        $this->done('decision-'.$id, 'Решение по смете сохранено.');
    }

    public function recordBank(Finance $f): void
    {
        $this->record();
        $f->recordBank($this->actor(), $this->id, $this->bank, $this->key('bank'));
        $this->done('bank', 'Подтверждённое поступление зарегистрировано. Распределите его по счёту.');
    }

    private function ownReceipt(): void
    {
        abort_unless(DB::table('payment_receipts')->where('id', $this->receipt)->where('service_request_id', $this->id)->exists(), 404);
    }

    public function allocate(Finance $f): void
    {
        $this->record();
        $this->ownReceipt();
        $f->allocate($this->actor(), (int) $this->receipt, (int) $this->invoice, (int) $this->package, $this->amount, $this->reason, $this->key('allocate'));
        $this->done('allocate', 'Поступление распределено.');
    }

    public function reverseAllocation(Finance $f): void
    {
        $this->record();
        abort_unless(DB::table('payment_allocations')->join('payment_receipts', 'payment_receipts.id', '=', 'payment_allocations.payment_receipt_id')->where('payment_allocations.id', $this->allocation)->where('service_request_id', $this->id)->exists(), 404);
        $f->reverseAllocation($this->actor(), (int) $this->allocation, $this->amount, $this->reason, $this->key('reverse'));
        $this->done('reverse', 'Распределение отменено отдельной записью. Свободные средства можно распределить заново или вернуть.');
    }

    public function approveRefund(Finance $f): void
    {
        $this->record();
        $this->ownReceipt();
        $f->approveRefund($this->actor(), (int) $this->receipt, $this->amount, $this->reason, $this->key('refund'));
        $this->done('refund', 'Возврат разрешён. Выполните его и подтвердите документом.');
    }

    public function confirmRefund(Finance $f): void
    {
        $this->record();
        abort_unless(DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('refunds.id', $this->refund)->where('service_request_id', $this->id)->exists(), 404);
        $f->confirmBankRefund($this->actor(), (int) $this->refund, $this->reference, $this->evidence, $this->key('confirm'));
        $this->done('confirm', 'Банковский возврат подтверждён.');
    }

    public function reverseBankReceipt(Finance $finance): void
    {
        $this->record();
        $this->ownReceipt();
        $finance->reverseBankReceipt($this->actor(), (int) $this->receipt, $this->reason, $this->evidence, $this->key('bank-reverse'));
        $this->done('bank-reverse', 'Банковское поступление сторнировано отдельной записью. Исходный документ сохранён.');
    }

    public function creditActual(Finance $finance): void
    {
        $this->record();
        abort_unless(DB::table('finance_documents')->where('id', $this->invoice)->where('service_request_id', $this->id)->exists(), 404);
        $finance->creditActual($this->actor(), (int) $this->invoice, $this->amount, $this->reason, $this->key('credit'));
        $this->done('credit', 'Кредит выпущен. Избыточные распределения и зачёты отменены отдельными записями; приёмка работ сохранена.');
    }

    public function loadRevision(int $estimate): void
    {
        $this->record();
        abort_unless($this->actor()->fresh()->hasRole('finance'), 403);
        $record = DB::table('estimates')->where('id', $estimate)->where('service_request_id', $this->id)->firstOrFail();
        $this->lines = collect(json_decode($record->lines, true))->filter(fn ($line) => ! DB::table('work_packages')->where('id', $line['package_id'])->where('status', 'cancelled')->exists())->map(fn ($line) => ['package_id' => $line['package_id'], 'kind' => $line['kind'], 'material_id' => $line['material_id'], 'description' => $line['description'], 'quantity' => Fixed::display($line['quantity_milli']), 'rate' => Fixed::display($line['rate_kopecks'], 2)])->values()->all();
        $this->reason = '';
        $this->refreshVersion();
    }

    public function checkout(int $invoice, ProviderCommands $commands): void
    {
        $this->record();
        abort_unless(DB::table('finance_documents')->where('id', $invoice)->where('service_request_id', $this->id)->exists(), 404);
        $commands->checkout($this->actor(), $invoice, $this->key('checkout-'.$invoice));
        $this->done('checkout-'.$invoice, 'Тестовая оплата готовится. Дождитесь ссылки; не создавайте ещё один платёж.');
    }

    public function dispatchRefund(int $refund, ProviderCommands $commands): void
    {
        $this->record();
        abort_unless(DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('refunds.id', $refund)->where('service_request_id', $this->id)->exists(), 404);
        $commands->refund($this->actor(), $refund, $this->key('dispatch-'.$refund));
        $this->done('dispatch-'.$refund, 'Тестовый возврат поставлен на отправку. Подтверждение появится после проверки провайдера.');
    }

    public function requestProviderBinding(int $attempt, ProviderCommands $commands): void
    {
        $this->record();
        abort_unless(DB::table('provider_attempts')->where('id', $attempt)->where('service_request_id', $this->id)->exists(), 404);
        $commands->requestBinding($this->actor(), $attempt, (string) ($this->providerCandidates[$attempt] ?? ''), $this->reason, $this->key('bind-'.$attempt));
        unset($this->providerCandidates[$attempt]);
        $this->done('bind-'.$attempt, 'Идентификатор поставлен на проверку через API. Деньги ещё не подтверждены; новый платёж не создаётся.');
    }

    public function reconcilePayment(int $attempt, ProviderCommands $commands): void
    {
        $this->record();
        abort_unless(DB::table('provider_attempts')->where('id', $attempt)->where('service_request_id', $this->id)->exists(), 404);
        $commands->reconcile($this->actor(), $attempt, $this->reason, $this->key('reconcile-'.$attempt));
        $this->done('reconcile-'.$attempt, 'Проверка поставлена в очередь. Новая оплата или возврат не создаются.');
    }

    public function reviewFiscal(int $record, Fiscalization $fiscal): void
    {
        $this->record();
        app(Finance::class)->authorize($this->actor());
        abort_unless(DB::table('fiscal_receipt_records')->where('id', $record)->where('service_request_id', $this->id)->exists(), 404);
        $fiscal->review($this->actor(), $record, (string) ($this->fiscalReasons[$record] ?? ''), $this->key('fiscal-'.$record));
        unset($this->fiscalReasons[$record]);
        $this->done('fiscal-'.$record, 'Утверждённая схема сохранена отдельной записью. Чек не отправлен; деньги и исходные документы не изменились.');
    }

    public function render()
    {
        $r = $this->record();
        $f = app(Finance::class);
        $invoices = DB::table('finance_documents')->where('service_request_id', $this->id)->orderBy('id')->get()->map(fn ($i) => (object) [...(array) $i, 'debt' => $f->debt($i), 'effective_total' => array_sum($f->effectiveShares($i))]);

        return view('livewire.billing', ['requestRecord' => $r, 'staff' => $this->actor()->fresh()->hasRole('finance'), 'fiscalRecords' => $this->actor()->fresh()->hasRole('finance') ? app(Fiscalization::class)->records($this->id) : collect(), 'packages' => $r->packages()->where('kind', 'paid')->get(), 'materials' => DB::table('materials')->where('active', true)->get(['id', 'name', 'unit', 'default_rate_kopecks']), 'estimates' => DB::table('estimates')->leftJoin('estimate_decisions', 'estimate_decisions.estimate_id', '=', 'estimates.id')->where('service_request_id', $this->id)->orderByDesc('estimates.id')->get(['estimates.*', 'estimate_decisions.decision']), 'invoices' => $invoices, 'corrections' => DB::table('finance_corrections')->join('finance_documents', 'finance_documents.id', '=', 'finance_corrections.credit_document_id')->where('service_request_id', $this->id)->get(['finance_corrections.*'])->keyBy('credit_document_id'), 'receiptReversals' => DB::table('bank_receipt_reversals')->join('payment_receipts', 'payment_receipts.id', '=', 'bank_receipt_reversals.payment_receipt_id')->where('service_request_id', $this->id)->pluck('payment_receipt_id')->all(), 'sandboxEnabled' => app(YooKassa::class)->enabled(), 'attempts' => DB::table('provider_attempts')->where('service_request_id', $this->id)->orderByDesc('id')->get(['id', 'finance_document_id', 'refund_id', 'kind', 'status', 'amount_kopecks', 'confirmation_url', 'locked_at', 'updated_at', 'provider_id', 'candidate_provider_id', 'first_sent_at', 'failure_code']), 'balance' => $f->balance($this->id), 'allocations' => $this->actor()->hasRole('finance') ? DB::table('payment_allocations')->join('payment_receipts', 'payment_receipts.id', '=', 'payment_allocations.payment_receipt_id')->where('service_request_id', $this->id)->whereNull('reversal_of_id')->get(['payment_allocations.*']) : collect(), 'receipts' => $this->actor()->hasRole('finance') ? DB::table('payment_receipts')->where('service_request_id', $this->id)->get() : collect(), 'refunds' => DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('service_request_id', $this->id)->get(['refunds.*', 'payment_receipts.source'])]);
    }
}
