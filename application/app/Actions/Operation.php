<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Operation
{
    public function run(User $actor, string $key, string $action, array $input, callable $effect): array
    {
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();
        abort_unless($actor->fresh()?->active, 403);
        $hash = hash('sha256', json_encode([$action, $input], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $key, $action, $hash, $effect) {
            $inserted = DB::table('action_executions')->insertOrIgnore([
                'actor_id' => $actor->id, 'operation_key' => $key, 'action' => $action,
                'fingerprint' => $hash, 'result' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('action_executions')->where('actor_id', $actor->id)->where('operation_key', $key)->lockForUpdate()->first();
            if (! hash_equals($row->fingerprint, $hash)) {
                throw ValidationException::withMessages(['action' => 'Действие изменилось. Обновите страницу перед повтором.']);
            }
            if (! $inserted) {
                return json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
            }
            if ((str_starts_with($action, 'stock.') || str_starts_with($action, 'purchase.'))
                && ! in_array($action, ['stock.demand', 'stock.reserve', 'stock.release', 'stock.issue', 'stock.return'])) {
                DB::table('stock_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            }
            $result = $effect();
            DB::table('action_executions')->where('id', $row->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }

    public function event(User $actor, int $requestId, string $action, array $data = []): void
    {
        $audit = DB::table('audit_events')->insertGetId(['actor_id' => $actor->id, 'service_request_id' => $requestId, 'action' => $action, 'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('outbox_events')->insert(['audit_event_id' => $audit, 'payload' => json_encode(['request_id' => $requestId, 'actor_id' => $actor->id, 'action' => $action, ...array_intersect_key($data, array_flip(['package_id', 'visit_id', 'round_ids', 'message_id']))]), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }
}
