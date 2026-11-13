<?php

namespace Tests\Feature;

use App\Actions\Identity\TwoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(bool $staff = true): User
    {
        $org = DB::table('organizations')->insertGetId(['name' => 'Fictional client']);

        return User::factory()->create(['kind' => $staff ? 'staff' : 'client', 'organization_id' => $staff ? null : $org, 'roles' => $staff ? ['technician'] : [], 'accept_work' => false]);
    }

    public function test_enrolled_staff_cannot_bypass_mfa_through_a_business_page(): void
    {
        $u = $this->user();
        $u->forceFill(['totp_confirmed_at' => now()])->save();
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/')->assertRedirect(route('mfa'));
    }

    public function test_all_staff_roles_can_sign_in_with_only_a_password_in_the_real_portal(): void
    {
        config(['portal.demo' => false]);
        foreach (User::STAFF_ROLES as $role) {
            $u = $this->user();
            $u->forceFill(['roles' => [$role], 'password' => 'fictional-test-passphrase'])->save();
            $this->post('/login', ['email' => $u->email, 'password' => 'fictional-test-passphrase'])->assertRedirect(route('home'));
            $response = $this->get('/');
            if ($role === 'administrator') {
                $response->assertRedirect(route('accounts'));
            } else {
                $response->assertOk();
            }
            $this->assertFalse($u->requiresMfa());
            $this->assertNull($u->fresh()->totp_secret);
            $this->post('/logout')->assertRedirect(route('login'));
        }
    }

    public function test_representatives_can_sign_in_without_authenticator_setup(): void
    {
        $u = $this->user(false);
        $u->forceFill(['accept_work' => true])->save();
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/')->assertOk();
        $this->get('/help')->assertOk()->assertSee('Подключить дополнительную защиту');
        $this->assertFalse($u->requiresMfa());
    }

    public function test_unfinished_optional_setup_does_not_block_work(): void
    {
        $u = $this->user();
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/two-factor')->assertOk()->assertSee('Продолжить без кода входа');
        $this->assertNotNull($u->fresh()->totp_secret);
        $this->assertNull($u->fresh()->totp_confirmed_at);
        $this->get('/')->assertOk();
    }

    public function test_contact_optional_mfa_does_not_block_business_pages(): void
    {
        $u = $this->user(false);
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/')->assertOk();
    }

    public function test_session_version_change_revokes_access(): void
    {
        $u = $this->user(false);
        $u->forceFill(['session_version' => 2])->save();
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/')->assertRedirect(route('login'));
    }

    public function test_totp_secret_is_encrypted_and_code_cannot_replay(): void
    {
        $u = $this->user();
        $secret = (new Google2FA)->generateSecretKey();
        $u->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        $this->assertNotSame($secret, DB::table('users')->where('id', $u->id)->value('totp_secret'));
        $code = (new Google2FA)->getCurrentOtp($secret);
        $service = app(TwoFactor::class);
        $this->assertTrue($service->verify($u, $code));
        $this->assertFalse($service->verify($u, $code));
    }

    public function test_recovery_codes_are_hashed_and_single_use(): void
    {
        $u = $this->user();
        $u->forceFill(['totp_secret' => (new Google2FA)->generateSecretKey()])->save();
        $service = app(TwoFactor::class);
        $codes = $service->recoveryCodes($u);
        $this->assertTrue(Hash::check($codes[0], $u->fresh()->recovery_codes[0]));
        $this->assertTrue($service->verify($u, $codes[0]));
        $this->assertFalse($service->verify($u, $codes[0]));
    }

    public function test_guided_setup_produces_recovery_codes_and_restores_session(): void
    {
        $u = $this->user();
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/two-factor')->assertOk()->assertSee('Подключить код входа');
        $code = (new Google2FA)->getCurrentOtp($u->fresh()->totp_secret);
        $this->post('/two-factor', ['code' => $code])->assertOk()->assertSessionHas('mfa_passed', true)->assertSee('Сохраните резервные коды');
        $this->assertNotNull($u->fresh()->totp_confirmed_at);
        $this->get('/')->assertOk();
    }

    public function test_enabled_mfa_can_be_disabled_with_the_current_password(): void
    {
        $u = $this->user();
        $secret = (new Google2FA)->generateSecretKey();
        $u->forceFill(['password' => 'fictional-test-passphrase', 'totp_secret' => $secret, 'totp_confirmed_at' => now(), 'recovery_codes' => ['test-hash']])->save();
        $this->actingAs($u)->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $this->post('/two-factor/disable', ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->assertSame($secret, $u->fresh()->totp_secret);
        $this->post('/two-factor/disable', ['password' => 'fictional-test-passphrase'])->assertRedirect(route('login'));
        $u->refresh();
        $this->assertGuest();
        $this->assertNull($u->totp_secret);
        $this->assertNull($u->totp_confirmed_at);
        $this->assertNull($u->recovery_codes);
        $this->assertSame(2, $u->session_version);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $u->id, 'action' => 'identity.mfa_disabled']);
    }

    public function test_demo_password_command_is_blocked_outside_local_demo(): void
    {
        $u = $this->user(false);
        config(['portal.demo' => true]);
        $this->artisan('portal:password', ['email' => $u->email])->assertExitCode(1);
        $this->app['env'] = 'local';
        config(['portal.demo' => false]);
        $this->artisan('portal:password', ['email' => $u->email])->assertExitCode(1);
    }

    public function test_demo_password_command_revokes_sessions_without_resetting_mfa(): void
    {
        $u = $this->user();
        $u->forceFill(['email' => 'staff@example.test', 'totp_secret' => (new Google2FA)->generateSecretKey(), 'totp_confirmed_at' => now()])->save();
        $secret = $u->totp_secret;
        $this->app['env'] = 'local';
        config(['portal.demo' => true]);
        $this->artisan('portal:password', ['email' => $u->email])->expectsQuestion('New password (at least 12 characters)', 'fictional-test-passphrase')->expectsQuestion('Confirm password', 'fictional-test-passphrase')->assertExitCode(0);
        $this->assertTrue(Hash::check('fictional-test-passphrase', $u->fresh()->password));
        $this->assertSame(2, $u->fresh()->session_version);
        $this->assertSame($secret, $u->fresh()->totp_secret);
        $data = DB::table('audit_events')->where('action', 'identity.demo_password_changed')->value('data');
        $this->assertStringNotContainsString('fictional-test-passphrase', $data);
    }

    public function test_demo_password_command_rejects_mismatched_passwords(): void
    {
        $u = $this->user(false);
        $u->forceFill(['email' => 'contact@example.test'])->save();
        $this->app['env'] = 'local';
        config(['portal.demo' => true]);
        $this->artisan('portal:password', ['email' => $u->email])->expectsQuestion('New password (at least 12 characters)', 'fictional-test-passphrase')->expectsQuestion('Confirm password', 'different-passphrase')->assertExitCode(1);
        $this->assertSame(1, $u->fresh()->session_version);
    }

    public function test_notification_inbox_requires_mfa_only_when_enabled(): void
    {
        $u = $this->user();
        $this->actingAs($u)->withSession(['account_version' => 1])->get('/notifications')->assertOk();
        $u->forceFill(['totp_confirmed_at' => now()])->save();
        $this->get('/notifications')->assertRedirect(route('mfa'));
    }

    public function test_public_registration_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'none@example.test', 'password' => 'invalid'])->assertRedirect();
        }
        $this->post('/login', ['email' => 'none@example.test', 'password' => 'invalid'])->assertStatus(429);
    }
}
