<?php

namespace App\Actions\Notifications;

use App\Models\AcceptanceRound;
use App\Models\RequestMessage;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPackage;
use Illuminate\Support\Facades\DB;

class DeliverPortalOutbox
{
    public function execute(int $limit = 100): int
    {
        $count = 0;
        while ($count < $limit) {
            $delivered = DB::transaction(function () {
                $q = DB::table('outbox_events')->where('status', 'pending')->orderBy('id');
                $row = (DB::getDriverName() === 'pgsql' ? $q->lock('for update skip locked') : $q->lockForUpdate())->first();
                if (! $row) {
                    return false;
                }
                $event = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $context = json_decode(DB::table('audit_events')->where('id', $row->audit_event_id)->value('data'), true, flags: JSON_THROW_ON_ERROR);
                $event += array_intersect_key($context, array_flip(['package_id', 'visit_id', 'round_ids', 'message_id']));
                $r = ServiceRequest::find($event['request_id']);
                $title = $this->title($event['action']);
                if ($r && $title) {
                    $packageIds = $this->packageIds($event);
                    foreach (User::where('active', true)->where('id', '!=', $event['actor_id'])->get() as $user) {
                        if (! ServiceRequest::visibleTo($user)->whereKey($r->id)->exists()) {
                            continue;
                        }
                        $package = $packageIds ? WorkPackage::visibleTo($user)->whereIn('id', $packageIds)->where('service_request_id', $r->id)->first() : null;
                        if ($packageIds && ! $package) {
                            continue;
                        }
                        if (! $this->eligible($user, $event, $package)) {
                            continue;
                        }
                        DB::table('portal_notifications')->insertOrIgnore([
                            'user_id' => $user->id, 'outbox_event_id' => $row->id, 'service_request_id' => $r->id,
                            'work_package_id' => $package?->id, 'title' => $title, 'audience' => $event['action'] === 'finance.fiscal_reviewed' ? 'finance_internal' : (str_starts_with($event['action'], 'finance.') && ($user->hasRole('finance') || ($user->kind === 'client' && $user->accept_work)) ? 'finance' : 'general'), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $noticeId = DB::table('portal_notifications')->where('user_id', $user->id)->where('outbox_event_id', $row->id)->value('id');
                        app(EmailDelivery::class)->enqueue($user->id, notice: $noticeId);
                    }
                }
                DB::table('outbox_events')->where('id', $row->id)->update(['status' => 'delivered', 'attempts' => $row->attempts + 1, 'delivered_at' => now(), 'updated_at' => now()]);

                return true;
            }, 3);
            if (! $delivered) {
                break;
            } $count++;
        }

        return $count;
    }

    private function title(string $action): ?string
    {
        return match ($action) {
            'request.created' => 'Получена новая заявка',
            'visit.scheduled' => 'Выезд запланирован',
            'visit.changed' => 'Изменено время или назначение выезда',
            'visit.cancelled' => 'Выезд отменён или больше не требуется',
            'visit.interrupt' => 'Выезд прерван: требуется помощь диспетчера',
            'package.lead_changed' => 'Изменён ведущий мастер',
            'result.submitted', 'visit.finish_submit' => 'Проверьте результат работ',
            'result.rejected' => 'Нужно рассмотреть замечание клиента',
            'result.accepted' => 'Работы приняты',
            'message.public', 'message.internal' => 'Новое сообщение по заявке',
            'request.cancelled' => 'Заявка отменена',
            'finance.estimate_issued' => 'Рассмотрите новую версию сметы',
            'finance.estimate_approved' => 'Смета утверждена',
            'finance.estimate_rejected' => 'Есть замечание к смете',
            'finance.invoice_issued' => 'Готов счёт по принятым работам',
            'finance.payment_received' => 'Поступление подтверждено',
            'finance.receipt_reversed' => 'Банковское поступление сторнировано',
            'finance.invoice_credited' => 'Выпущен кредит к принятому счёту',
            'finance.payment_allocated' => 'Поступление распределено',
            'finance.allocation_reversed' => 'Изменено распределение средств',
            'finance.refund_approved' => 'Возврат разрешён',
            'finance.refund_succeeded' => 'Возврат подтверждён',
            'finance.fiscal_reviewed' => 'Схема фискальной проверки сохранена',
            'finance.payment_unknown', 'finance.refund_unknown' => 'Проверяем результат платёжной операции',
            default => null,
        };
    }

    private function packageIds(array $event): array
    {
        if (isset($event['package_id'])) {
            return [(int) $event['package_id']];
        }
        if (isset($event['visit_id'])) {
            return Visit::whereKey($event['visit_id'])->pluck('work_package_id')->all();
        }
        if (isset($event['round_ids'])) {
            return AcceptanceRound::whereIn('id', $event['round_ids'])->pluck('work_package_id')->all();
        }
        if (isset($event['message_id'])) {
            $visitId = RequestMessage::whereKey($event['message_id'])->value('visit_id');
            if ($visitId) {
                return Visit::whereKey($visitId)->pluck('work_package_id')->all();
            }
        }

        return [];
    }

    private function eligible(User $user, array $event, ?WorkPackage $package): bool
    {
        return match ($event['action']) {
            'finance.estimate_issued' => $user->hasRole('finance') || $user->hasRole('dispatcher') || ($user->kind === 'client' && $user->accept_work),
            'finance.estimate_approved', 'finance.estimate_rejected' => $user->hasRole('finance') || $user->hasRole('dispatcher') || WorkPackage::where('service_request_id', $event['request_id'])->where('lead_id', $user->id)->exists(),
            'finance.invoice_issued', 'finance.payment_received', 'finance.receipt_reversed', 'finance.invoice_credited', 'finance.payment_allocated', 'finance.allocation_reversed', 'finance.refund_approved', 'finance.refund_succeeded', 'finance.payment_unknown', 'finance.refund_unknown' => $user->hasRole('finance') || ($user->kind === 'client' && $user->accept_work),
            'finance.fiscal_reviewed' => $user->hasRole('finance'),
            'request.created', 'visit.interrupt' => $user->hasRole('dispatcher'),
            'result.submitted', 'visit.finish_submit' => $user->kind === 'client' && $user->accept_work,
            'result.rejected' => $user->hasRole('dispatcher') || ($user->hasRole('technician') && $package?->lead_id === $user->id),
            'visit.scheduled', 'visit.changed', 'visit.cancelled' => $user->kind === 'client' || $package?->lead_id === $user->id || Visit::whereKey($event['visit_id'])->where('technician_id', $user->id)->exists(),
            'package.lead_changed' => $package?->lead_id === $user->id,
            'message.internal' => $user->kind === 'staff',
            default => true,
        };
    }
}
