<?php

namespace Tests\Feature;

use App\Actions\Identity\ManageAccounts;
use App\Livewire\Accounts;
use App\Models\AccountInvitation;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkPackage;
use App\Policies\ServiceRequestPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private ManageAccounts $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['kind' => 'staff', 'organization_id' => null, 'roles' => ['administrator'], 'totp_confirmed_at' => now()]);
        $org = DB::table('organizations')->insertGetId(['name' => 'Fictional organization']);
        $this->site = Site::create(['organization_id' => $org, 'name' => 'Fictional site', 'address' => 'Test address']);
        $this->accounts = app(ManageAccounts::class);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function input(?User $user = null, array $changes = []): array
    {
        return array_replace([
            'name' => $user?->name ?? 'Fictional contact', 'email' => $user?->email ?? 'contact@example.test',
            'kind' => $user?->kind ?? 'client', 'organization_id' => $user?->organization_id ?? $this->site->organization_id,
            'roles' => $user?->roles ?? [], 'permissions' => $user?->permissions ?? [],
            'site_ids' => $user ? $user->sites()->pluck('sites.id')->all() : [$this->site->id],
            'accept_work' => $user?->accept_work ?? false, 'active' => $user?->active ?? true,
            'reason' => 'Verified authorized access request',
        ], $changes, ($user?->kind === 'staff' && ! array_key_exists('organization_id', $changes)) ? ['organization_id' => null] : []);
    }

    private function contact(): User
    {
        return User::findOrFail($this->accounts->create($this->admin, $this->input(), $this->key())['id']);
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('An invalid management action was accepted.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function leadPackage(User $tech): WorkPackage
    {
        $contract = Contract::create(['organization_id' => $this->site->organization_id, 'number' => 'TEST-IDENTITY', 'title' => 'Test coverage', 'starts_on' => now()->subYear(), 'ends_on' => now()->addYear(), 'covered_categories' => ['plumbing'], 'terms' => []]);
        $r = ServiceRequest::create(['number' => 'TEST-ACCOUNT', 'site_id' => $this->site->id, 'organization_id' => $this->site->organization_id, 'author_id' => $this->admin->id, 'title' => 'Test problem', 'description' => 'Test']);

        return $r->packages()->create(['ordinal' => 1, 'title' => 'Test work', 'scope' => 'Test', 'category' => 'plumbing', 'kind' => 'covered', 'contract_id' => $contract->id, 'contract_snapshot' => [], 'lead_id' => $tech->id, 'checklist_template' => []]);
    }

    public function test_creation_and_lost_response_retry_have_one_account_and_invitation(): void
    {
        $input = $this->input();
        $key = $this->key();
        $result = $this->accounts->create($this->admin, $input, $key);
        $this->assertSame($result, $this->accounts->create($this->admin, $input, $key));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('account_invitations', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame([$this->site->id], User::find($result['id'])->sites()->pluck('sites.id')->all());
        $audit = DB::table('audit_events')->value('data');
        $this->assertStringNotContainsString('signature', $audit);
        $this->assertStringNotContainsString('password', $audit);
    }

    public function test_invitation_is_signed_single_use_and_allows_password_only_staff_sign_in(): void
    {
        $result = $this->accounts->create($this->admin, $this->input(null, ['kind' => 'staff', 'organization_id' => null, 'roles' => ['technician'], 'site_ids' => []]), $this->key());
        $invitation = AccountInvitation::find($result['invitation_id']);
        $url = $invitation->setupUrl();
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post($url, ['password' => 'fictional-long-passphrase', 'password_confirmation' => 'fictional-long-passphrase'])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('fictional-long-passphrase', $invitation->user->fresh()->password));
        $this->assertNotNull($invitation->fresh()->consumed_at);
        $this->post($url, ['password' => 'another-long-passphrase', 'password_confirmation' => 'another-long-passphrase'])->assertStatus(410);
        $this->get($url)->assertStatus(410)->assertDontSee($invitation->user->email);
        $this->post('/login', ['email' => $invitation->user->email, 'password' => 'fictional-long-passphrase'])->assertRedirect(route('home'));
        $this->get('/')->assertOk();
    }

    public function test_unsigned_or_tampered_invitation_cannot_disclose_account(): void
    {
        $user = $this->contact();
        $invite = AccountInvitation::first();
        $this->get('/invitations/'.$invite->id)->assertForbidden()->assertDontSee($user->email)->assertSee('Доступ ограничен');
        $this->get($invite->setupUrl().'&other=1')->assertForbidden()->assertSee('Ссылка недействительна');
        $this->assertNull($invite->fresh()->consumed_at);
    }

    public function test_expired_and_replaced_invitations_cannot_set_password(): void
    {
        $user = $this->contact();
        $old = AccountInvitation::first();
        $this->accounts->invite($this->admin, $user->id, $user->session_version, 'Replacement approved', $this->key());
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->get($old->setupUrl())->assertStatus(410);
        $new = AccountInvitation::latest('id')->first();
        $url = $new->setupUrl();
        $this->travel(49)->hours();
        $this->post($url, ['password' => 'fictional-long-passphrase', 'password_confirmation' => 'fictional-long-passphrase'])->assertForbidden();
        $this->assertNull($new->fresh()->consumed_at);
    }

    public function test_invitation_password_validation_does_not_consume_link(): void
    {
        $this->contact();
        $invite = AccountInvitation::first();
        $this->post($invite->setupUrl(), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertNull($invite->fresh()->consumed_at);
    }

    public function test_invitation_endpoint_is_rate_limited(): void
    {
        $this->contact();
        $url = AccountInvitation::first()->setupUrl();
        for ($i = 0; $i < 5; $i++) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertStatus(429);
    }

    public function test_ordinary_dispatcher_cannot_read_or_mutate_accounts(): void
    {
        $dispatcher = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher'], 'totp_confirmed_at' => now()]);
        $this->actingAs($dispatcher)->withSession(['account_version' => 1, 'mfa_passed' => true])->get('/accounts')->assertForbidden();
        Livewire::test(Accounts::class)->assertForbidden();
        $this->expectException(HttpException::class);
        $this->accounts->create($dispatcher, $this->input(), $this->key());
    }

    public function test_client_and_technician_do_not_gain_management_from_permission_alone(): void
    {
        $user = $this->contact();
        $user->forceFill(['permissions' => ['manage_directory']])->save();
        $this->assertFalse($user->canManageUsers());
        $tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician'], 'permissions' => ['manage_directory']]);
        $this->assertFalse($tech->canManageUsers());
    }

    public function test_dispatcher_with_directory_permission_can_manage_but_admin_has_no_business_access(): void
    {
        $dispatcher = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher'], 'permissions' => ['manage_directory'], 'totp_confirmed_at' => now()]);
        $this->actingAs($dispatcher)->withSession(['account_version' => 1, 'mfa_passed' => true])->get('/accounts')->assertOk();
        $this->assertFalse(app(ServiceRequestPolicy::class)->create($this->admin));
    }

    public function test_account_page_requires_mfa_and_is_not_cached(): void
    {
        $this->actingAs($this->admin)->withSession(['account_version' => 1])->get('/accounts')->assertRedirect(route('mfa'));
        $response = $this->withSession(['mfa_passed' => true])->get('/accounts')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_last_manager_cannot_be_deactivated_or_demoted(): void
    {
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $this->admin->id, 1, $this->input($this->admin, ['active' => false]), $this->key()));
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $this->admin->id, 1, $this->input($this->admin, ['roles' => ['dispatcher']]), $this->key()));
        $this->assertTrue($this->admin->fresh()->canManageUsers());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_other_active_manager_allows_deactivation_without_deleting_history(): void
    {
        User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher'], 'permissions' => ['manage_directory']]);
        $this->accounts->update($this->admin, $this->admin->id, 1, $this->input($this->admin, ['active' => false]), $this->key());
        $this->assertFalse($this->admin->fresh()->active);
        $this->assertSame(2, $this->admin->fresh()->session_version);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_cross_organization_membership_and_mixed_identity_are_rejected(): void
    {
        $other = DB::table('organizations')->insertGetId(['name' => 'Other organization']);
        $this->assertRejected(fn () => $this->accounts->create($this->admin, $this->input(null, ['organization_id' => $other]), $this->key()));
        $this->assertRejected(fn () => $this->accounts->create($this->admin, $this->input(null, ['roles' => ['administrator']]), $this->key()));
        $this->assertRejected(fn () => $this->accounts->create($this->admin, $this->input(null, ['kind' => 'staff', 'roles' => ['technician']]), $this->key()));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_membership_change_revokes_sessions_links_and_records_before_after(): void
    {
        $user = $this->contact();
        DB::table('sessions')->insert(['id' => 'test-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $key = $this->key();
        $input = $this->input($user, ['site_ids' => []]);
        $result = $this->accounts->update($this->admin, $user->id, 1, $input, $key);
        $this->assertSame($result, $this->accounts->update($this->admin, $user->id, 1, $input, $key));
        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertFalse(AccountInvitation::first()->usable());
        $data = json_decode(DB::table('audit_events')->where('action', 'identity.updated')->value('data'), true);
        $this->assertSame([$this->site->id], $data['before']['site_ids']);
        $this->assertSame([], $data['after']['site_ids']);
        $this->actingAs($user->fresh())->withSession(['account_version' => 1])->get('/')->assertRedirect(route('login'));
    }

    public function test_stale_update_and_account_kind_change_are_rejected(): void
    {
        $user = $this->contact();
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $user->id, 99, $this->input($user), $this->key()));
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $user->id, 1, $this->input($user, ['kind' => 'staff', 'organization_id' => null, 'roles' => ['technician'], 'site_ids' => []]), $this->key()));
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_technician_lead_responsibility_blocks_deactivation_and_role_removal(): void
    {
        $tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $package = $this->leadPackage($tech);
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $tech->id, 1, $this->input($tech, ['active' => false]), $this->key()));
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $tech->id, 1, $this->input($tech, ['roles' => ['finance']]), $this->key()));
        $package->update(['status' => 'accepted']);
        $this->accounts->update($this->admin, $tech->id, 1, $this->input($tech, ['active' => false]), $this->key());
        $this->assertFalse($tech->fresh()->active);
    }

    public function test_scheduled_and_executing_visits_block_technician_deactivation(): void
    {
        $tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $lead = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $package = $this->leadPackage($lead);
        $visit = $package->visits()->create(['technician_id' => $tech->id, 'planned_start' => now()->addHour(), 'planned_end' => now()->addHours(2), 'checklist' => []]);
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $tech->id, 1, $this->input($tech, ['active' => false]), $this->key()));
        $visit->update(['status' => 'in_progress']);
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $tech->id, 1, $this->input($tech, ['active' => false]), $this->key()));
        $visit->update(['status' => 'completed']);
        $this->accounts->update($this->admin, $tech->id, 1, $this->input($tech, ['active' => false]), $this->key());
        $this->assertFalse($tech->fresh()->active);
    }

    public function test_mfa_reset_requires_identity_evidence_and_restores_password_only_sign_in(): void
    {
        $user = $this->contact();
        $secret = (new Google2FA)->generateSecretKey();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now(), 'totp_last_step' => 123, 'recovery_codes' => ['test-hash']])->save();
        $this->assertRejected(fn () => $this->accounts->resetMfa($this->admin, $user->id, 1, '', 'Device lost', $this->key()));
        $key = $this->key();
        $result = $this->accounts->resetMfa($this->admin, $user->id, 1, 'Verified through known company contact', 'Device lost', $key);
        $this->assertSame($result, $this->accounts->resetMfa($this->admin, $user->id, 1, 'Verified through known company contact', 'Device lost', $key));
        $user->refresh();
        $this->assertNull($user->totp_secret);
        $this->assertNull($user->recovery_codes);
        $this->assertNull($user->totp_last_step);
        $this->assertFalse($user->requiresMfa());
        $this->assertSame(2, $user->session_version);
        $this->assertStringNotContainsString($secret, DB::table('audit_events')->where('action', 'identity.mfa_reset')->value('data'));
        $this->actingAs($user)->withSession(['account_version' => 2])->get('/')->assertOk();
        $this->get('/two-factor')->assertOk();
        $code = (new Google2FA)->getCurrentOtp($user->fresh()->totp_secret);
        $this->post('/two-factor', ['code' => $code])->assertOk();
        $this->assertFalse($user->fresh()->mfa_reset_required);
    }

    public function test_password_recovery_preserves_mfa_and_invalidates_invitation(): void
    {
        $user = $this->contact();
        $secret = (new Google2FA)->generateSecretKey();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        $token = Password::createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'recovered-long-passphrase', 'password_confirmation' => 'recovered-long-passphrase'])->assertRedirect(route('login'));
        $this->assertSame($secret, $user->fresh()->totp_secret);
        $this->assertFalse(AccountInvitation::first()->usable());
    }

    public function test_last_dispatcher_manager_cannot_lose_directory_permission(): void
    {
        $this->admin->forceFill(['roles' => ['dispatcher'], 'permissions' => ['manage_directory']])->save();
        $this->assertRejected(fn () => $this->accounts->update($this->admin, $this->admin->id, 1, $this->input($this->admin, ['permissions' => []]), $this->key()));
        $this->assertTrue($this->admin->fresh()->canManageUsers());
    }

    public function test_mfa_reset_cannot_be_self_authorized_or_target_inactive_account(): void
    {
        $this->assertRejected(fn () => $this->accounts->resetMfa($this->admin, $this->admin->id, 1, 'Known contact', 'Device lost', $this->key()));
        $user = $this->contact();
        $user->forceFill(['active' => false])->save();
        $this->assertRejected(fn () => $this->accounts->resetMfa($this->admin, $user->id, 1, 'Known contact', 'Device lost', $this->key()));
        $this->assertRejected(fn () => $this->accounts->invite($this->admin, $user->id, 1, 'New link', $this->key()));
    }

    public function test_removed_management_grant_is_checked_again_for_direct_actions(): void
    {
        $actor = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher'], 'permissions' => ['manage_directory']]);
        DB::table('users')->where('id', $actor->id)->update(['permissions' => '[]']);
        $this->assertTrue($actor->canManageUsers());
        $this->expectException(HttpException::class);
        $this->accounts->create($actor, $this->input(), $this->key());
    }

    public function test_organization_change_replaces_scope_without_altering_old_requests(): void
    {
        $user = $this->contact();
        $request = ServiceRequest::create(['number' => 'TEST-SCOPE', 'site_id' => $this->site->id, 'organization_id' => $this->site->organization_id, 'author_id' => $user->id, 'title' => 'Test', 'description' => 'Test']);
        $other = DB::table('organizations')->insertGetId(['name' => 'Other organization']);
        $newSite = Site::create(['organization_id' => $other, 'name' => 'Other site', 'address' => 'Test']);
        $this->assertTrue(ServiceRequest::visibleTo($user)->whereKey($request->id)->exists());
        $this->accounts->update($this->admin, $user->id, 1, $this->input($user, ['organization_id' => $other, 'site_ids' => [$newSite->id]]), $this->key());
        $this->assertFalse(ServiceRequest::visibleTo($user->fresh())->whereKey($request->id)->exists());
        $this->assertSame($this->site->organization_id, $request->fresh()->organization_id);
        $this->assertSame([$newSite->id], $user->sites()->pluck('sites.id')->all());
    }

    public function test_representative_grant_keeps_password_only_sign_in_and_removal_restores_explicit_site_scope(): void
    {
        $user = $this->contact();
        $this->accounts->update($this->admin, $user->id, 1, $this->input($user, ['accept_work' => true]), $this->key());
        $this->assertFalse($user->fresh()->requiresMfa());
        $this->actingAs($user->fresh())->withSession(['account_version' => 2])->get('/')->assertOk();
        $this->accounts->update($this->admin, $user->id, 2, $this->input($user->fresh(), ['accept_work' => false]), $this->key());
        $this->assertSame([$this->site->id], $user->sites()->pluck('sites.id')->all());
    }

    public function test_unknown_roles_permissions_and_blank_reasons_cannot_create_account(): void
    {
        $this->assertRejected(fn () => $this->accounts->create($this->admin, $this->input(null, ['roles' => ['superuser']]), $this->key()));
        $this->assertRejected(fn () => $this->accounts->create($this->admin, $this->input(null, ['permissions' => ['force_accept']]), $this->key()));
        $this->assertRejected(fn () => $this->accounts->create($this->admin, $this->input(null, ['reason' => '   ']), $this->key()));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_recovery_cannot_reactivate_disabled_account(): void
    {
        $user = $this->contact();
        $token = Password::createToken($user);
        $user->forceFill(['active' => false])->save();
        $oldPassword = $user->password;
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'recovered-long-passphrase', 'password_confirmation' => 'recovered-long-passphrase'])->assertForbidden();
        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->assertFalse($user->fresh()->active);
    }

    public function test_new_invitation_preserves_existing_mfa_and_replay_does_not_revoke_twice(): void
    {
        $user = $this->contact();
        $secret = (new Google2FA)->generateSecretKey();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        $key = $this->key();
        $result = $this->accounts->invite($this->admin, $user->id, 1, 'Authorized replacement', $key);
        $this->assertSame($result, $this->accounts->invite($this->admin, $user->id, 1, 'Authorized replacement', $key));
        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertSame($secret, $user->fresh()->totp_secret);
        $this->assertDatabaseCount('account_invitations', 2);
    }

    public function test_livewire_mutations_are_rate_limited_per_actor(): void
    {
        $this->actingAs($this->admin)->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $component = Livewire::test(Accounts::class)->call('createForm');
        for ($i = 0; $i < 5; $i++) {
            $component->call('save')->assertHasErrors();
        }
        $component->call('save')->assertStatus(429);
        $this->assertDatabaseCount('account_invitations', 0);
    }

    public function test_admin_without_business_role_lands_in_account_workbench(): void
    {
        $this->actingAs($this->admin)->withSession(['account_version' => 1, 'mfa_passed' => true])->get('/')->assertRedirect(route('accounts'));
    }

    public function test_livewire_staff_invitation_clears_client_fields(): void
    {
        $this->actingAs($this->admin)->withSession(['account_version' => 1, 'mfa_passed' => true]);
        Livewire::test(Accounts::class)->call('createForm')
            ->set('form.organization_id', $this->site->organization_id)
            ->set('form.site_ids', [$this->site->id])->set('form.accept_work', true)
            ->set('form.kind', 'staff')->assertSet('form.organization_id', null)->assertSet('form.site_ids', [])->assertSet('form.accept_work', false)
            ->set('form.name', 'Fictional technician')->set('form.email', 'new-tech@example.test')
            ->set('form.roles', ['technician'])->set('reason', 'Authorized staff invitation')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new-tech@example.test', 'kind' => 'staff', 'organization_id' => null]);
    }

    public function test_livewire_target_cannot_be_replaced_by_client_supplied_property(): void
    {
        $this->actingAs($this->admin)->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $component = Livewire::test(Accounts::class)->call('edit', $this->admin->id);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('editing', 999);
    }

    public function test_livewire_lost_response_retry_reuses_initial_create_key(): void
    {
        $this->actingAs($this->admin)->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $component = Livewire::test(Accounts::class)->call('createForm');
        $input = $this->input();
        $key = $component->get('operationKeys')['create'];
        $result = $this->accounts->create($this->admin, $input, $key);
        $component->set('form', collect($input)->except('reason')->all())->set('reason', $input['reason'])->call('save')->assertHasNoErrors()->assertSet('editing', $result['id']);
        $this->assertDatabaseCount('account_invitations', 1);
        $this->assertDatabaseCount('users', 2);
    }
}
