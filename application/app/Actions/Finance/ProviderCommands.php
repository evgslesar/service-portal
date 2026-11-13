<?php

namespace App\Actions\Finance;

use App\Actions\Operation;
use App\Actions\ServiceRequests\Workflow;
use App\Integrations\YooKassa;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Fixed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProviderCommands
{
    public function __construct(private YooKassa $provider, private Finance $finance, private Operation $operations) {}

    private function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['payment' => $message]);
        }
    }

    private function representative(User $actor, int $request): void
    {
        $fresh = $actor->fresh();
        abort_unless($fresh?->active && $fresh->kind === 'client' && $fresh->accept_work, 403);
        ServiceRequest::visibleTo($fresh)->findOrFail($request);
    }

    public function checkout(User $actor, int $invoiceId, string $key): array
    {
        $invoice = DB::table('finance_documents')->where('id', $invoiceId)->firstOrFail();
        $this->representative($actor, $invoice->service_request_id);
        $this->check($this->provider->enabled(), 'Тестовая оплата пока не подключена. Реальные платежи выключены.');

        return $this->operations->run($actor, $key, 'provider.checkout', [$invoiceId], function () use ($actor, $invoice) {
            $r = ServiceRequest::whereKey($invoice->service_request_id)->lockForUpdate()->firstOrFail();
            $this->representative($actor, $r->id);
            $this->check(! in_array($r->status, ['closed', 'cancelled']), 'Новая оплата закрытой или отменённой заявки недоступна.');
            $this->check(! DB::table('provider_attempts')->where('finance_document_id', $invoice->id)->whereNotIn('status', ['succeeded', 'cancelled'])->exists(), 'Оплата уже проверяется. Не создавайте ещё один платёж.');
            $shares = [];
            foreach ($this->finance->effectiveShares($invoice) as $package => $share) {
                $shares[$package] = max(0, $share - $this->finance->allocated($invoice->id, (int) $package));
            }
            $amount = min(array_sum($shares), $this->finance->debt($invoice));
            $this->check($amount > 0, 'По этому счёту нет суммы к оплате.');
            $shares = Finance::proportional($shares, $amount);
            $command = (string) Str::uuid();
            $payload = ['amount' => ['value' => Fixed::display($amount, 2), 'currency' => 'RUB'], 'capture' => true, 'confirmation' => ['type' => 'redirect', 'return_url' => route('requests.billing', $r->id)], 'description' => 'Тестовая оплата счёта '.$invoice->number, 'metadata' => ['portal_attempt' => $command]];
            $id = $this->insert($actor, $r->id, 'payment', $command, $amount, $shares, $payload, $invoice->id);

            return ['id' => $id];
        });
    }

    public function refund(User $actor, int $refundId, string $key): array
    {
        $this->finance->authorize($actor);
        $this->check($this->provider->enabled(), 'Тестовый провайдер не подключён.');

        return $this->operations->run($actor, $key, 'provider.refund', [$refundId], function () use ($actor, $refundId) {
            $refund = DB::table('refunds')->where('id', $refundId)->firstOrFail();
            $receipt = DB::table('payment_receipts')->where('id', $refund->payment_receipt_id)->firstOrFail();
            $r = ServiceRequest::whereKey($receipt->service_request_id)->lockForUpdate()->firstOrFail();
            $refund = DB::table('refunds')->where('id', $refundId)->lockForUpdate()->firstOrFail();
            $this->finance->authorize($actor);
            $original = DB::table('provider_attempts')->where('payment_receipt_id', $receipt->id)->where('kind', 'payment')->where('status', 'succeeded')->first();
            $this->check($receipt->source === 'yookassa_sandbox' && $refund->status === 'approved' && $original !== null && ! DB::table('provider_attempts')->where('refund_id', $refundId)->exists(), 'Нужен разрешённый возврат исходной подтверждённой тестовой оплаты.');
            $command = (string) Str::uuid();
            $payload = ['payment_id' => $original->provider_id, 'amount' => ['value' => Fixed::display($refund->amount_kopecks, 2), 'currency' => 'RUB'], 'description' => 'portal-refund:'.$command];
            $id = $this->insert($actor, $r->id, 'refund', $command, $refund->amount_kopecks, [], $payload, refund: $refundId);
            DB::table('refunds')->where('id', $refundId)->update(['status' => 'pending', 'updated_at' => now()]);

            return ['id' => $id];
        });
    }

    private function insert(User $actor, int $request, string $kind, string $command, int $amount, array $shares, array $payload, ?int $invoice = null, ?int $refund = null): int
    {
        $id = DB::table('provider_attempts')->insertGetId(['service_request_id' => $request, 'finance_document_id' => $invoice, 'refund_id' => $refund, 'actor_id' => $actor->id, 'kind' => $kind, 'command_key' => $command, 'merchant_id' => (string) config('portal.yookassa.shop_id'), 'amount_kopecks' => $amount, 'shares' => json_encode($shares, JSON_THROW_ON_ERROR), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->history($id, 'queued', 'command');

        return $id;
    }

    private function history(int $id, string $status, string $source, ?string $failure = null): void
    {
        DB::table('provider_attempt_events')->insert(['provider_attempt_id' => $id, 'status' => $status, 'source' => $source, 'failure_code' => $failure, 'created_at' => now()]);
    }

    public function execute(int $limit = 100): int
    {
        abort_unless(DB::transactionLevel() === 0 || app()->environment('testing'), 500);
        if (! $this->provider->enabled()) {
            return 0;
        }
        $count = 0;
        while ($count < $limit && ($attempt = $this->claim())) {
            try {
                if ($attempt->merchant_id !== (string) config('portal.yookassa.shop_id')) {
                    throw new RuntimeException('Merchant changed');
                }
                $candidate = ! $attempt->provider_id && $attempt->candidate_provider_id !== null;
                if ($candidate) {
                    $attempt->provider_id = $attempt->candidate_provider_id;
                }
                if (! $attempt->provider_id) {
                    if ($attempt->first_sent_at && CarbonImmutable::parse($attempt->first_sent_at)->addHours(24)->isPast()) {
                        $this->state($attempt, 'review', 'idempotency_window_expired');
                        $count++;

                        continue;
                    }
                    if (! $attempt->first_sent_at && $attempt->kind === 'payment' && (! User::find($attempt->actor_id)?->active || in_array(ServiceRequest::findOrFail($attempt->service_request_id)->status, ['closed', 'cancelled']))) {
                        $this->state($attempt, 'cancelled');
                        $count++;

                        continue;
                    }
                    DB::transaction(function () use ($attempt) {
                        DB::table('provider_attempts')->where('id', $attempt->id)->where('lease', $attempt->lease)->update(['first_sent_at' => $attempt->first_sent_at ?? now(), 'send_count' => $attempt->send_count + 1, 'updated_at' => now()]);
                        $this->history($attempt->id, 'creating', 'transport');
                    });
                    $created = $this->provider->create($attempt->kind, $attempt->command_key, json_decode($attempt->payload, true, flags: JSON_THROW_ON_ERROR));
                    $id = $created['id'] ?? '';
                    if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
                        throw new RuntimeException('Invalid provider ID');
                    }
                    DB::table('provider_attempts')->where('id', $attempt->id)->where('lease', $attempt->lease)->whereNull('provider_id')->update(['provider_id' => $id]);
                    $attempt->provider_id = $id;
                }
                $object = $this->provider->fetch($attempt->kind, $attempt->provider_id);
                $this->verify($attempt, $object);
                $this->apply($attempt->id, $object, $candidate ? 'operator_binding_verified' : 'reconciliation');
            } catch (ValidationException) {
                $this->state($attempt, 'quarantined', 'verification_failed');
            } catch (Throwable) {
                $this->state($attempt, 'unknown', 'transport_unknown');
            }
            $count++;
        }

        return $count;
    }

    private function claim(): ?object
    {
        return DB::transaction(function () {
            $query = DB::table('provider_attempts')->whereIn('status', ['queued', 'creating', 'pending', 'unknown'])->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subMinutes(5)))->orderBy('id');
            $row = (DB::getDriverName() === 'pgsql' ? $query->lock('for update skip locked') : $query->lockForUpdate())->first();
            if (! $row) {
                return null;
            }
            $row->lease = (string) Str::uuid();
            DB::table('provider_attempts')->where('id', $row->id)->update(['lease' => $row->lease, 'locked_at' => now(), 'available_at' => now()->addMinutes(2), 'updated_at' => now()]);

            return $row;
        }, 3);
    }

    private function verifyPayment(array $object, string $merchant): void
    {
        $this->check(($object['test'] ?? false) === true && ($object['recipient']['account_id'] ?? null) === $merchant, 'Провайдер или тестовый магазин не совпадает.');
    }

    private function verify(object $attempt, array $object): void
    {
        $this->check(($object['id'] ?? '') === $attempt->provider_id && ($object['amount']['currency'] ?? '') === 'RUB' && is_string($object['amount']['value'] ?? null) && Fixed::money($object['amount']['value']) === $attempt->amount_kopecks, 'Сумма, валюта или идентификатор не совпадают.');
        if ($attempt->kind === 'payment') {
            $this->verifyPayment($object, $attempt->merchant_id);
            $this->check(($object['metadata']['portal_attempt'] ?? null) === $attempt->command_key && in_array($object['status'] ?? '', ['pending', 'waiting_for_capture', 'succeeded', 'canceled']), 'Платёж не связан с сохранённой командой.');
            $this->check(($object['status'] ?? '') !== 'succeeded' || ($object['paid'] ?? false) === true, 'Платёж не подтверждён.');
        } else {
            $payload = json_decode($attempt->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->check(($object['payment_id'] ?? '') === $payload['payment_id'] && ($object['description'] ?? '') === $payload['description'] && in_array($object['status'] ?? '', ['pending', 'succeeded', 'canceled']), 'Возврат не связан с исходной оплатой.');
            $original = DB::table('provider_attempts')->where('kind', 'payment')->where('provider_id', $payload['payment_id'])->where('service_request_id', $attempt->service_request_id)->where('status', 'succeeded')->first();
            $this->check($original !== null && $original->merchant_id === $attempt->merchant_id && $attempt->amount_kopecks <= $original->amount_kopecks, 'Исходная подтверждённая оплата не найдена.');
            $payment = $this->provider->fetch('payment', $payload['payment_id']);
            $this->verify($original, $payment);
            $this->check(($payment['status'] ?? '') === 'succeeded' && ($payment['paid'] ?? false) === true, 'Исходная оплата не подтверждена.');
        }
    }

    private function apply(int $id, array $object, string $source): void
    {
        $snapshot = DB::table('provider_attempts')->where('id', $id)->firstOrFail();
        DB::transaction(function () use ($id, $snapshot, $object, $source) {
            $r = ServiceRequest::whereKey($snapshot->service_request_id)->lockForUpdate()->firstOrFail();
            $attempt = DB::table('provider_attempts')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->check(! $attempt->provider_id || $attempt->provider_id === $object['id'], 'Идентификатор операции уже связан с другим ответом.');
            if ($attempt->status === 'succeeded') {
                if (($object['status'] ?? '') === 'succeeded') {
                    app(Fiscalization::class)->observeProviderLocked(User::findOrFail($attempt->actor_id), $attempt, $object);
                }

                return;
            }
            if ($attempt->status === 'cancelled' && ($object['status'] ?? '') !== 'succeeded') {
                return;
            }
            if ($source === 'operator_binding_verified' && ! $attempt->provider_id) {
                $this->check($attempt->candidate_provider_id === $object['id'], 'Проверяемый идентификатор уже изменился.');
            }
            $actor = User::findOrFail($attempt->actor_id);
            $status = match ($object['status']) {
                'succeeded' => 'succeeded', 'canceled' => 'cancelled', default => 'pending'
            };
            if ($status === 'succeeded' && $attempt->kind === 'payment') {
                $receipt = $this->finance->receiptLocked($actor, $r, 'yookassa_sandbox', 'yookassa:'.$attempt->merchant_id.':'.$object['id'], $object['id'], $attempt->merchant_id, now()->format('Y-m-d'), 'Тестовая оплата ЮKassa', 'Подтверждено через API тестового магазина', $attempt->amount_kopecks);
                $this->finance->allocateProviderReceiptLocked($actor, $r, $receipt, $attempt->finance_document_id, json_decode($attempt->shares, true, flags: JSON_THROW_ON_ERROR));
                DB::table('provider_attempts')->where('id', $id)->update(['payment_receipt_id' => $receipt]);
                $attempt->payment_receipt_id = $receipt;
                $invoice = DB::table('finance_documents')->where('id', $attempt->finance_document_id)->where('service_request_id', $r->id)->firstOrFail();
                app(Fiscalization::class)->recordLocked($actor, $r->id, 'payment', $receipt, $invoice->kind === 'advance' ? 'advance' : 'final_payment', $attempt->amount_kopecks, ['payment_receipt_id' => $receipt, 'finance_document_id' => $invoice->id, 'provider_attempt_id' => $id, 'source' => 'yookassa_sandbox', 'header' => json_decode($invoice->header, true), 'test' => true]);
                $this->operations->event($actor, $r->id, 'finance.payment_received');
            } elseif ($attempt->kind === 'refund') {
                if ($status === 'succeeded') {
                    $refund = DB::table('refunds')->where('id', $attempt->refund_id)->lockForUpdate()->firstOrFail();
                    $this->finance->confirmedRefundLocked($actor, $refund, $object['id'], 'Подтверждено через API тестового магазина');
                    $this->operations->event($actor, $r->id, 'finance.refund_succeeded');
                } else {
                    DB::table('refunds')->where('id', $attempt->refund_id)->update(['status' => $status === 'cancelled' ? 'failed' : 'pending', 'updated_at' => now()]);
                }
            }
            $url = $object['confirmation']['confirmation_url'] ?? null;
            if (! is_string($url) || strlen($url) > 2048 || ! str_starts_with($url, 'https://') || ! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['yoomoney.ru', 'yookassa.ru'], true)) {
                $url = null;
            }
            DB::table('provider_attempts')->where('id', $id)->update(['status' => $status, 'provider_id' => $object['id'], 'candidate_provider_id' => null, 'confirmation_url' => $url, 'lease' => null, 'locked_at' => null, 'failure_code' => null, 'available_at' => now()->addMinutes(2), 'updated_at' => now()]);
            $this->history($id, $status, $source);
            if ($status === 'succeeded') {
                $attempt->status = $status;
                $attempt->provider_id = $object['id'];
                app(Fiscalization::class)->observeProviderLocked($actor, $attempt, $object);
            }
            app(Workflow::class)->aggregate($r);
        }, 3);
    }

    private function state(object $attempt, string $status, ?string $failure = null): void
    {
        DB::transaction(function () use ($attempt, $status, $failure) {
            $r = ServiceRequest::whereKey($attempt->service_request_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('provider_attempts')->where('id', $attempt->id)->where('lease', $attempt->lease)->lockForUpdate()->first();
            if (! $row || $row->status === 'succeeded') {
                return;
            }
            DB::table('provider_attempts')->where('id', $row->id)->update(['status' => $status, 'failure_code' => $failure, 'lease' => null, 'locked_at' => null, 'available_at' => now()->addMinutes(2), 'updated_at' => now()]);
            $this->history($row->id, $status, 'transport', $failure);
            if ($row->kind === 'refund') {
                DB::table('refunds')->where('id', $row->refund_id)->update(['status' => 'unknown', 'updated_at' => now()]);
            }
            if (! in_array($row->status, ['unknown', 'review', 'quarantined']) && in_array($status, ['unknown', 'review', 'quarantined'])) {
                $this->operations->event(User::findOrFail($row->actor_id), $r->id, 'finance.'.$row->kind.'_unknown');
            }
        }, 3);
    }

    public function receive(string $event, string $id): void
    {
        $this->check(in_array($event, ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture', 'refund.succeeded']), 'Неверный вид события.');
        $this->check((bool) preg_match('/^[a-f0-9-]{36}$/D', $id), 'Неверный идентификатор.');
        DB::table('provider_webhooks')->insertOrIgnore(['kind' => str_starts_with($event, 'payment.') ? 'payment' : 'refund', 'provider_id' => $id, 'event' => $event, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function verifyWebhooks(int $limit = 100): int
    {
        abort_unless(DB::transactionLevel() === 0 || app()->environment('testing'), 500);
        if (! $this->provider->enabled()) {
            return 0;
        }
        $count = 0;
        foreach (DB::table('provider_webhooks')->where('status', 'pending')->orderBy('id')->limit($limit)->get() as $hook) {
            try {
                $object = $this->provider->fetch($hook->kind, $hook->provider_id);
                $command = $hook->kind === 'payment' ? ($object['metadata']['portal_attempt'] ?? '') : substr((string) ($object['description'] ?? ''), strlen('portal-refund:'));
                $attempt = DB::table('provider_attempts')->where('kind', $hook->kind)->where('command_key', $command)->first();
                $this->check($attempt !== null && (! $attempt->provider_id || $attempt->provider_id === $hook->provider_id), 'Неизвестная связь операции.');
                $attempt->provider_id = $hook->provider_id;
                $this->verify($attempt, $object);
                $this->apply($attempt->id, $object, 'webhook_verified');
                DB::table('provider_webhooks')->where('id', $hook->id)->update(['status' => 'verified', 'updated_at' => now()]);
            } catch (ValidationException) {
                DB::table('provider_webhooks')->where('id', $hook->id)->update(['status' => 'quarantined', 'failure_code' => 'verification_failed', 'updated_at' => now()]);
            } catch (Throwable) {
                // Leave transport uncertainty pending for reconciliation; do not trust callback status.
            }
            $count++;
        }

        return $count;
    }

    public function requestBinding(User $actor, int $id, string $providerId, string $reason, string $key): array
    {
        $this->finance->authorize($actor);
        $this->check($this->provider->enabled(), 'Тестовый провайдер не подключён.');
        $input = Validator::make(['provider_id' => strtolower(trim($providerId)), 'reason' => trim($reason)], ['provider_id' => 'required|uuid', 'reason' => 'required|string|max:5000'])->validate();

        return $this->operations->run($actor, $key, 'provider.binding_request', [$id, $input], function () use ($actor, $id, $input) {
            $snapshot = DB::table('provider_attempts')->where('id', $id)->firstOrFail();
            ServiceRequest::whereKey($snapshot->service_request_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('provider_attempts')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->finance->authorize($actor);
            $this->check(! $row->provider_id && $row->first_sent_at !== null && $row->locked_at === null && in_array($row->status, ['unknown', 'review', 'quarantined']), 'Укажите идентификатор для отправленной операции с неизвестным результатом.');
            $this->check(! DB::table('provider_attempts')->where('kind', $row->kind)->where('provider_id', $input['provider_id'])->exists(), 'Идентификатор уже связан с другой операцией.');
            DB::table('provider_attempts')->where('id', $id)->update(['candidate_provider_id' => $input['provider_id'], 'status' => 'unknown', 'failure_code' => null, 'available_at' => now(), 'updated_at' => now()]);
            $this->operations->event($actor, $row->service_request_id, 'provider.binding_requested', ['provider_attempt_id' => $id, ...$input]);
            $this->history($id, 'unknown', 'operator_binding_requested');

            return ['id' => $id];
        });
    }

    public function reconcile(User $actor, int $id, string $reason, string $key): array
    {
        $this->finance->authorize($actor);

        return $this->operations->run($actor, $key, 'provider.reconcile', [$id, $reason], function () use ($actor, $id, $reason) {
            $snapshot = DB::table('provider_attempts')->where('id', $id)->firstOrFail();
            ServiceRequest::whereKey($snapshot->service_request_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('provider_attempts')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->finance->authorize($actor);
            $this->check(filled(trim($reason)) && mb_strlen($reason) <= 5000 && ! in_array($row->status, ['succeeded', 'cancelled']) && $row->locked_at === null, 'Укажите основание проверки незавершённой операции.');
            $status = ($row->provider_id || $row->candidate_provider_id) ? 'unknown' : ($row->first_sent_at && CarbonImmutable::parse($row->first_sent_at)->addHours(24)->isPast() ? 'review' : $row->status);
            DB::table('provider_attempts')->where('id', $id)->update(['status' => $status, 'available_at' => now(), 'updated_at' => now()]);
            $this->operations->event($actor, $row->service_request_id, 'provider.reconciliation_requested', ['provider_attempt_id' => $id, 'reason' => trim($reason)]);
            $this->history($id, $status, 'operator');

            return ['id' => $id];
        });
    }
}
