<?php

namespace App\Actions\Notifications;

use App\Actions\Operation;
use App\Mail\PortalMessage;
use App\Models\AccountInvitation;
use App\Models\PortalNotification;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailDelivery
{
    public function enqueue(int $user, ?int $notice = null, ?int $invitation = null): void
    {
        abort_unless(DB::transactionLevel() > 0 && (($notice !== null) xor ($invitation !== null)), 500);
        DB::table('notification_deliveries')->insertOrIgnore(['user_id' => $user, 'portal_notification_id' => $notice, 'account_invitation_id' => $invitation, 'source_key' => $notice !== null ? 'notice:'.$notice : 'invitation:'.$invitation, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function enabled(): bool
    {
        return (bool) config('portal.delivery.email_enabled') && (app()->environment('testing') || ! in_array(config('mail.default'), ['log', 'array'], true));
    }

    public function execute(int $limit = 100): int
    {
        abort_unless(DB::transactionLevel() === 0 || app()->environment('testing'), 500);
        if (! $this->enabled()) {
            return 0;
        }
        $count = 0;
        while ($count < $limit) {
            $delivery = $this->claim();
            if (! $delivery) {
                break;
            }
            $status = 'sent';
            $failure = null;
            try {
                $message = $this->message($delivery);
                if ($message === null) {
                    $status = 'suppressed';
                } else {
                    // The durable lease transaction has committed before transport I/O.
                    Mail::to($message['user']->email)->send(new PortalMessage($message['title'], $message['url'], $message['invitation']));
                }
            } catch (Throwable) {
                // Transport exceptions can contain credentials or message bodies; never persist them.
                $failure = 'transport_failed';
                $status = $delivery->attempts >= 5 ? 'failed' : 'pending';
            }
            DB::transaction(function () use ($delivery, $status, $failure) {
                $changed = DB::table('notification_deliveries')->where('id', $delivery->id)->where('lease', $delivery->lease)->where('status', 'processing')->update(['status' => $status, 'failure_code' => $failure, 'available_at' => now()->addSeconds(min(3600, 60 * (2 ** min($delivery->attempts, 6)))), 'locked_at' => null, 'lease' => null, 'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now()]);
                if ($changed) {
                    DB::table('notification_delivery_attempts')->where('lease', $delivery->lease)->update(['status' => $status === 'pending' ? 'failed' : $status, 'failure_code' => $failure, 'finished_at' => now()]);
                }
            });
            $count++;
        }

        return $count;
    }

    private function claim(): ?object
    {
        return DB::transaction(function () {
            $query = DB::table('notification_deliveries')->where(function ($q) {
                $q->where(fn ($q) => $q->where('status', 'pending')->where('available_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('status', 'processing')->where('locked_at', '<', now()->subMinutes(5)));
            })->orderBy('id');
            $row = (DB::getDriverName() === 'pgsql' ? $query->lock('for update skip locked') : $query->lockForUpdate())->first();
            if (! $row) {
                return null;
            }
            if ($row->lease) {
                DB::table('notification_delivery_attempts')->where('lease', $row->lease)->whereNull('finished_at')->update(['status' => 'unknown', 'failure_code' => 'lease_expired', 'finished_at' => now()]);
            }
            $row->lease = (string) Str::uuid();
            $row->attempts++;
            DB::table('notification_deliveries')->where('id', $row->id)->update(['status' => 'processing', 'lease' => $row->lease, 'locked_at' => now(), 'attempts' => $row->attempts, 'updated_at' => now()]);
            DB::table('notification_delivery_attempts')->insert(['notification_delivery_id' => $row->id, 'lease' => $row->lease, 'status' => 'processing', 'started_at' => now()]);

            return $row;
        }, 3);
    }

    private function message(object $delivery): ?array
    {
        $user = User::find($delivery->user_id);
        if (! $user?->active) {
            return null;
        }
        if ($delivery->account_invitation_id !== null) {
            $invitation = AccountInvitation::find($delivery->account_invitation_id);
            if (! $invitation || $invitation->user_id !== $user->id || ! $invitation->usable()) {
                return null;
            }

            return ['user' => $user, 'title' => 'Приглашение в портал обслуживания', 'url' => $invitation->setupUrl(), 'invitation' => true];
        }
        if (! $user->email_verified_at || DB::table('notification_preferences')->where('user_id', $user->id)->value('email') === 0 || DB::table('notification_preferences')->where('user_id', $user->id)->value('email') === false) {
            return null;
        }
        $notice = PortalNotification::visibleTo($user)->find($delivery->portal_notification_id);
        if (! $notice) {
            return null;
        }

        return ['user' => $user, 'title' => $notice->title, 'url' => route($notice->audience === 'finance' ? 'requests.billing' : 'requests.show', $notice->service_request_id), 'invitation' => false];
    }

    public function operatorQuery(User $actor): Builder
    {
        $actor = $actor->fresh();
        abort_unless($actor?->canManageUsers() || $actor?->hasRole('finance'), 403);
        $query = DB::table('notification_deliveries');
        if (! $actor->canManageUsers()) {
            $query->whereIn('portal_notification_id', DB::table('portal_notifications')->where('audience', 'finance')->select('id'));
        }

        return $query;
    }

    public function retry(User $actor, int $id, string $reason, string $key): array
    {
        $this->operatorQuery($actor)->where('id', $id)->firstOrFail();

        return app(Operation::class)->run($actor, $key, 'notification.retry', [$id, $reason], function () use ($actor, $id, $reason) {
            $row = $this->operatorQuery($actor)->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['failed', 'pending'], true) || trim($reason) === '' || mb_strlen($reason) > 5000) {
                throw ValidationException::withMessages(['delivery' => 'Повтор доступен для неотправленной записи. Укажите причину.']);
            }
            DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'failure_code' => null, 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'notification.retry', 'data' => json_encode(['delivery_id' => $id, 'reason' => trim($reason)], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return ['id' => $id];
        });
    }

    public function preference(User $actor, bool $enabled, string $key): array
    {
        return app(Operation::class)->run($actor, $key, 'notification.preference', [$enabled], function () use ($actor, $enabled) {
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $actor->id], ['email' => $enabled, 'updated_at' => now(), 'created_at' => now()]);

            return ['email' => $enabled];
        });
    }
}
