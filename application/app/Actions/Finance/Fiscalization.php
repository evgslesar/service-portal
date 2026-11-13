<?php

namespace App\Actions\Finance;

use App\Actions\Operation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Fiscalization
{
    public function __construct(private Operation $operations) {}

    public function mapping(string $scenario): array
    {
        $configuration = config('portal.finance.fiscal', []);
        $mapping = $configuration['scenarios'][$scenario] ?? null;
        if (($configuration['approved'] ?? false) !== true || ! is_array($mapping) || ! is_bool($mapping['required'] ?? null) || ! is_bool($configuration['prices_include_tax'] ?? null)) {
            throw ValidationException::withMessages(['fiscal' => 'Бухгалтер должен утвердить фискальную схему и явно определить этот сценарий.']);
        }
        $input = [
            'approved_by' => $configuration['approved_by'] ?? null,
            'approved_on' => $configuration['approved_on'] ?? null,
            'arrangement' => $configuration['arrangement'] ?? null,
            'tax_system' => $configuration['tax_system'] ?? null,
            'prices_include_tax' => $configuration['prices_include_tax'],
            'scenario' => $scenario,
            'required' => $mapping['required'],
        ];
        if ($mapping['required']) {
            $input += array_intersect_key($mapping, array_flip(['vat_code', 'payment_mode', 'labor_subject', 'material_subject']));
        }

        return Validator::make($input, [
            'approved_by' => 'required|string|max:255',
            'approved_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'arrangement' => 'required|string|max:255',
            'tax_system' => 'required|string|max:100',
            'prices_include_tax' => 'required|boolean',
            'scenario' => 'required|in:advance,final_payment,bank_transfer,final_settlement,advance_offset,refund,correction',
            'required' => 'required|boolean',
            'vat_code' => 'required_if:required,true|string|max:100',
            'payment_mode' => 'required_if:required,true|string|max:100',
            'labor_subject' => 'required_if:required,true|string|max:100',
            'material_subject' => 'required_if:required,true|string|max:100',
        ])->validate();
    }

    /**
     * Capture a ledger event for fiscal review, not an issued fiscal receipt.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function recordLocked(User $actor, int $requestId, string $basisType, int $basisId, string $scenario, int $amount, array $snapshot): int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        ServiceRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
        $this->validateBasis($requestId, $basisType, $basisId, $scenario, $amount);
        $existing = DB::table('fiscal_receipt_records')->where('basis_type', $basisType)->where('basis_id', $basisId)->first();
        if ($existing) {
            abort_unless($existing->service_request_id === $requestId && $existing->scenario === $scenario && $existing->amount_kopecks === $amount && json_decode($existing->snapshot, true, flags: JSON_THROW_ON_ERROR) === $snapshot, 409);

            return $existing->id;
        }
        Validator::make(['basis_type' => $basisType, 'scenario' => $scenario], [
            'basis_type' => 'required|in:payment,actual,offset,credit,bank_reversal,refund',
            'scenario' => 'required|in:advance,final_payment,bank_transfer,final_settlement,advance_offset,refund,correction',
        ])->validate();
        $id = DB::table('fiscal_receipt_records')->insertGetId([
            'service_request_id' => $requestId, 'actor_id' => $actor->id,
            'number' => DocumentNumber::next('FIS'), 'basis_type' => $basisType, 'basis_id' => $basisId,
            'scenario' => $scenario, 'amount_kopecks' => $amount, 'snapshot' => $this->json($snapshot),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $mapping = $this->mapping($scenario);
            $status = $mapping['required'] ? 'awaiting_transport' : 'not_required';
        } catch (ValidationException) {
            $mapping = null;
            $status = 'awaiting_configuration';
        }
        $this->state($actor, $id, 'basis:'.$basisType.':'.$basisId, $status, $mapping, ['source' => 'ledger_event']);

        return $id;
    }

    private function validateBasis(int $requestId, string $type, int $id, string $scenario, int $amount): void
    {
        $basis = match ($type) {
            'payment' => DB::table('payment_receipts')->where('id', $id)->first(),
            'actual', 'credit' => DB::table('finance_documents')->where('id', $id)->first(),
            'offset' => DB::table('advance_offsets')->join('finance_documents', 'finance_documents.id', '=', 'advance_offsets.actual_document_id')->where('advance_offsets.id', $id)->first(['advance_offsets.*', 'finance_documents.service_request_id']),
            'bank_reversal' => DB::table('bank_receipt_reversals')->join('payment_receipts', 'payment_receipts.id', '=', 'bank_receipt_reversals.payment_receipt_id')->where('bank_receipt_reversals.id', $id)->first(['bank_receipt_reversals.*', 'payment_receipts.service_request_id']),
            'refund' => DB::table('refunds')->join('payment_receipts', 'payment_receipts.id', '=', 'refunds.payment_receipt_id')->where('refunds.id', $id)->where('refunds.status', 'succeeded')->whereExists(fn ($q) => $q->selectRaw('1')->from('refund_confirmations')->whereColumn('refund_id', 'refunds.id'))->first(['refunds.*', 'payment_receipts.service_request_id']),
            default => null,
        };
        abort_unless($basis && $basis->service_request_id === $requestId, 409);
        $expectedAmount = in_array($type, ['actual', 'credit']) ? $basis->total_kopecks : $basis->amount_kopecks;
        if (in_array($type, ['bank_reversal', 'refund'])) {
            $expectedAmount = -$expectedAmount;
        }
        $expectedScenario = match ($type) {
            'actual' => $basis->kind === 'actual' ? 'final_settlement' : null,
            'credit' => in_array($basis->kind, ['advance_credit', 'actual_credit']) ? 'correction' : null,
            'offset' => $expectedAmount > 0 ? 'advance_offset' : 'correction',
            'bank_reversal' => 'correction',
            'refund' => 'refund',
            'payment' => $basis->source === 'bank' ? 'bank_transfer' : $this->paymentScenario($id),
        };
        abort_unless($expectedAmount === $amount && $expectedScenario === $scenario, 409);
    }

    private function paymentScenario(int $receiptId): ?string
    {
        $kind = DB::table('provider_attempts')->join('finance_documents', 'finance_documents.id', '=', 'provider_attempts.finance_document_id')->where('payment_receipt_id', $receiptId)->where('provider_attempts.kind', 'payment')->value('finance_documents.kind');

        return match ($kind) {
            'advance' => 'advance',
            'actual' => 'final_payment',
            default => null,
        };
    }

    public function review(User $actor, int $id, string $reason, string $key): array
    {
        app(Finance::class)->authorize($actor);
        $reason = Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:5000'])->validate()['reason'];

        return $this->operations->run($actor, $key, 'finance.fiscal_review', [$id, $reason], function () use ($actor, $id, $reason, $key) {
            $record = DB::table('fiscal_receipt_records')->find($id);
            abort_unless($record, 404);
            ServiceRequest::whereKey($record->service_request_id)->lockForUpdate()->firstOrFail();
            app(Finance::class)->authorize($actor);
            $mapping = $this->mapping($record->scenario);
            $this->state($actor, $id, 'review:'.$actor->id.':'.$key, $mapping['required'] ? 'awaiting_transport' : 'not_required', $mapping, ['source' => 'accountant_review', 'reason' => $reason]);
            $this->operations->event($actor, $record->service_request_id, 'finance.fiscal_reviewed', ['fiscal_record_id' => $id, 'reason' => $reason]);

            return ['id' => $id];
        });
    }

    /** Record only the registration flag from an already verified provider object. */
    public function observeProviderLocked(User $actor, object $attempt, array $object): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        ServiceRequest::whereKey($attempt->service_request_id)->lockForUpdate()->firstOrFail();
        abort_unless($attempt->status === 'succeeded' && ($object['status'] ?? null) === 'succeeded' && ($object['id'] ?? null) === $attempt->provider_id, 409);
        $basisType = $attempt->kind === 'payment' ? 'payment' : 'refund';
        $basisId = $attempt->kind === 'payment' ? $attempt->payment_receipt_id : $attempt->refund_id;
        if (! $basisId) {
            return;
        }
        $record = DB::table('fiscal_receipt_records')->where('basis_type', $basisType)->where('basis_id', $basisId)->where('service_request_id', $attempt->service_request_id)->first();
        if (! $record) {
            return;
        }
        $registration = $object['receipt_registration'] ?? null;
        $status = match ($registration) {
            'pending' => 'provider_pending',
            'succeeded' => 'provider_registered',
            'canceled' => 'provider_failed',
            default => 'provider_unknown',
        };
        $latest = DB::table('fiscal_receipt_states')->where('fiscal_receipt_record_id', $record->id)->where('status', 'like', 'provider_%')->latest('id')->first();
        if ($latest && ($latest->status === $status || $latest->status === 'provider_registered')) {
            return;
        }
        $this->state($actor, $record->id, 'provider:'.$attempt->id.':'.$status.':'.($latest->id ?? 0), $status, null, [
            'source' => 'verified_provider_api', 'provider_attempt_id' => $attempt->id,
            'provider_id' => $object['id'], 'registration' => is_string($registration) ? $registration : null,
            'test' => true,
        ]);
    }

    public function records(int $requestId): Collection
    {
        $records = DB::table('fiscal_receipt_records')->where('service_request_id', $requestId)->orderByDesc('id')->get();
        $history = DB::table('fiscal_receipt_states')->whereIn('fiscal_receipt_record_id', $records->pluck('id'))->orderBy('id')->get()->groupBy('fiscal_receipt_record_id');

        return $records->map(function (object $record) use ($history): object {
            $states = $history->get($record->id, collect());
            $record->configuration_status = $states->last(fn (object $state): bool => ! str_starts_with($state->status, 'provider_'))?->status ?? 'awaiting_configuration';
            $record->provider_status = $states->last(fn (object $state): bool => str_starts_with($state->status, 'provider_'))?->status;
            $record->states = $states;

            return $record;
        });
    }

    private function state(User $actor, int $id, string $key, string $status, ?array $mapping, array $evidence): void
    {
        DB::table('fiscal_receipt_states')->insert([
            'fiscal_receipt_record_id' => $id, 'actor_id' => $actor->id, 'event_key' => $key,
            'status' => $status, 'mapping' => $mapping === null ? null : $this->json($mapping),
            'evidence' => $this->json($evidence), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
