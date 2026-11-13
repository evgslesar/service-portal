<?php

namespace App\Actions\Identity;

use App\Actions\Notifications\EmailDelivery;
use App\Actions\Operation;
use App\Models\AccountInvitation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageAccounts
{
    public function __construct(private Operation $operations) {}

    public function authorize(User $actor): void
    {
        abort_unless($actor->fresh()?->canManageUsers(), 403);
    }

    private function lock(User $actor): void
    {
        // Serialize management changes to preserve the last-manager invariant.
        DB::table('identity_locks')->where('id', 1)->lockForUpdate()->first();
        $this->authorize($actor);
    }

    private function check(bool $condition, string $message, string $field = 'action'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function data(array $input, ?User $target = null): array
    {
        foreach (['name', 'email', 'reason'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (is_string($input['email'] ?? null)) {
            $input['email'] = strtolower($input['email']);
        }
        $input['organization_id'] ??= null;
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($target?->id)],
            'kind' => 'required|in:staff,client', 'organization_id' => 'nullable|integer',
            'roles' => 'present|array', 'roles.*' => ['required', 'distinct', Rule::in(User::STAFF_ROLES)],
            'permissions' => 'present|array', 'permissions.*' => ['required', 'distinct', Rule::in(User::STAFF_PERMISSIONS)],
            'site_ids' => 'present|array', 'site_ids.*' => 'required|integer|distinct',
            'accept_work' => 'required|boolean', 'active' => 'required|boolean',
            'reason' => 'required|string|max:5000',
        ])->validate();
        if ($target) {
            $this->check($data['kind'] === $target->kind, 'Тип учётной записи нельзя менять. Создайте отдельную учётную запись.', 'kind');
        }
        if ($data['kind'] === 'staff') {
            $this->check(! $data['organization_id'] && ! $data['accept_work'] && ! $data['site_ids'] && count($data['roles']) > 0, 'Сотруднику нужны роли, без клиентской организации, объектов и приёмки.', 'roles');
        } else {
            $this->check(! $data['roles'] && ! $data['permissions'], 'Клиенту нельзя выдавать роли и разрешения сотрудников.', 'roles');
            $organization = DB::table('organizations')->where('id', $data['organization_id'])->first();
            $this->check($organization !== null && (! $data['active'] || $organization->active), 'Выберите действующую организацию клиента.', 'organization_id');
            $sites = Site::whereIn('id', $data['site_ids'])->get();
            $this->check($sites->count() === count($data['site_ids']) && $sites->every(fn ($site) => $site->organization_id == $data['organization_id']), 'Все объекты должны принадлежать выбранной организации.', 'site_ids');
        }
        sort($data['roles']);
        sort($data['permissions']);
        $data['site_ids'] = array_map('intval', $data['site_ids']);
        sort($data['site_ids']);

        return $data;
    }

    private function snapshot(User $user): array
    {
        return [
            ...$user->only(['name', 'email', 'kind', 'organization_id', 'roles', 'permissions', 'accept_work', 'active']),
            'site_ids' => $user->sites()->orderBy('sites.id')->pluck('sites.id')->all(),
        ];
    }

    private function audit(User $actor, string $action, array $data): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => $action, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function revoke(User $user): void
    {
        $user->forceFill(['session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        AccountInvitation::where('user_id', $user->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function invitation(User $actor, User $user): AccountInvitation
    {
        $invitation = AccountInvitation::create(['user_id' => $user->id, 'actor_id' => $actor->id, 'account_version' => $user->session_version, 'expires_at' => now()->addHours(48)]);
        app(EmailDelivery::class)->enqueue($user->id, invitation: $invitation->id);

        return $invitation;
    }

    public function create(User $actor, array $input, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'identity.create', $input, function () use ($actor, $input) {
            $this->lock($actor);
            $data = $this->data($input);
            $this->check((bool) $data['active'], 'Новая учётная запись должна быть активна для приглашения.', 'active');
            $user = new User;
            $user->forceFill(collect($data)->except(['site_ids', 'reason'])->all());
            $user->forceFill(['password' => Str::random(64), 'email_verified_at' => null])->save();
            $user->sites()->sync($data['site_ids']);
            $invitation = $this->invitation($actor, $user);
            $this->audit($actor, 'identity.created', ['user_id' => $user->id, 'after' => $this->snapshot($user), 'reason' => $data['reason'], 'invitation_id' => $invitation->id]);

            return ['id' => $user->id, 'invitation_id' => $invitation->id];
        });
    }

    public function update(User $actor, int $id, int $version, array $input, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'identity.update', [$id, $version, $input], function () use ($actor, $id, $version, $input) {
            $this->lock($actor);
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check($user->session_version === $version, 'Права уже изменились. Откройте учётную запись заново.');
            $data = $this->data($input, $user);
            $before = $this->snapshot($user);
            $candidate = clone $user;
            $candidate->forceFill(collect($data)->except(['site_ids', 'reason'])->all());
            if ($user->canManageUsers() && ! $candidate->canManageUsers()) {
                $this->check(User::where('active', true)->where('kind', 'staff')->where('id', '!=', $id)->get()->contains(fn ($other) => $other->canManageUsers()), 'Нельзя лишить доступа последнего администратора пользователей.');
            }
            if ($user->hasRole('technician') && ! $candidate->hasRole('technician')) {
                $this->check(! Visit::where('technician_id', $id)->whereIn('status', ['scheduled', 'in_progress'])->exists(), 'Сначала переназначьте или завершите выезды мастера.');
                $this->check(! WorkPackage::where('lead_id', $id)->whereNotIn('status', ['accepted', 'cancelled'])->exists(), 'Сначала передайте или завершите ответственность за результаты.');
                $this->check(! DB::table('stock_positions')->join('stock_locations', 'stock_locations.id', '=', 'stock_positions.stock_location_id')->where('custodian_id', $id)->where('quantity_milli', '>', 0)->exists(), 'Сначала верните материалы или передайте место хранения другому ответственному мастеру.');
            }
            $user->forceFill(collect($data)->except(['site_ids', 'reason'])->all())->save();
            $user->sites()->sync($data['site_ids']);
            DB::table('password_reset_tokens')->where('email', $before['email'])->delete();
            if ($before['email'] !== $user->email) {
                $user->forceFill(['email_verified_at' => null])->save();
            }
            $this->revoke($user);
            $this->audit($actor, 'identity.updated', ['user_id' => $id, 'before' => $before, 'after' => $this->snapshot($user), 'reason' => $data['reason']]);

            return ['id' => $id];
        });
    }

    public function invite(User $actor, int $id, int $version, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'identity.invite', [$id, $version, $reason], function () use ($actor, $id, $version, $reason) {
            $this->lock($actor);
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:5000'])->validate();
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check($user->active && $user->session_version === $version, 'Учётная запись неактивна или уже изменена. Откройте её заново.');
            $this->revoke($user);
            $invitation = $this->invitation($actor, $user);
            $this->audit($actor, 'identity.invited', ['user_id' => $id, 'invitation_id' => $invitation->id, 'reason' => trim($reason)]);

            return ['id' => $id, 'invitation_id' => $invitation->id];
        });
    }

    public function resetMfa(User $actor, int $id, int $version, string $evidence, string $reason, string $key): array
    {
        $this->authorize($actor);

        return $this->operations->run($actor, $key, 'identity.mfa_reset', [$id, $version, $evidence, $reason], function () use ($actor, $id, $version, $evidence, $reason) {
            $this->lock($actor);
            Validator::make(['evidence' => trim($evidence), 'reason' => trim($reason)], ['evidence' => 'required|string|max:5000', 'reason' => 'required|string|max:5000'])->validate();
            $this->check($id !== $actor->id, 'Сброс собственной защиты выполняет другой администратор.');
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check($user->active && $user->session_version === $version, 'Учётная запись неактивна или уже изменена. Откройте её заново.');
            $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null, 'recovery_codes' => null, 'mfa_reset_required' => false])->save();
            $this->revoke($user);
            $this->audit($actor, 'identity.mfa_reset', ['user_id' => $id, 'identity_verification' => trim($evidence), 'reason' => trim($reason)]);

            return ['id' => $id];
        });
    }
}
