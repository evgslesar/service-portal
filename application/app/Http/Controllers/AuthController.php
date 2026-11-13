<?php

namespace App\Http\Controllers;

use App\Actions\Identity\TwoFactor;
use App\Models\AccountInvitation;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        if (! Auth::attempt([...$data, 'active' => true])) {
            return back()->withErrors(['email' => 'Проверьте почту и пароль.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $request->session()->forget('mfa_passed');
        $request->session()->put('account_version', $request->user()->session_version);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function mfa(Request $request)
    {
        $user = $request->user();
        if ($request->session()->get('mfa_passed') && $user->totp_confirmed_at) {
            return redirect()->intended(route('home'));
        }
        $qr = null;
        if (! $user->totp_confirmed_at) {
            DB::transaction(function () use ($user) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $locked->totp_secret) {
                    $locked->forceFill(['totp_secret' => (new Google2FA)->generateSecretKey()])->save();
                }
            });
            $user->refresh();
            $uri = (new Google2FA)->getQRCodeUrl(config('portal.company'), $user->email, $user->totp_secret);
            $qr = (new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd)))->writeString($uri);
        }

        return response()->view('auth.mfa', compact('qr', 'user'))->header('Cache-Control', 'no-store');
    }

    public function confirmMfa(Request $request, TwoFactor $mfa)
    {
        $data = $request->validate(['code' => 'required|string|max:32']);
        $codes = DB::transaction(function () use ($request, $mfa, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && $user->session_version === (int) $request->session()->get('account_version'), 403);
            if (! $mfa->verify($user, trim($data['code']))) {
                throw ValidationException::withMessages(['code' => 'Код не подошёл или уже использован. Введите новый код.']);
            }

            return $user->totp_confirmed_at ? null : $mfa->recoveryCodes($user);
        }, 3);
        $request->user()->refresh();
        $request->session()->regenerate();
        $request->session()->put('mfa_passed', true);
        if ($codes !== null) {
            return response()->view('auth.recovery', compact('codes'))->header('Cache-Control', 'no-store');
        }

        return redirect()->intended(route('home'));
    }

    public function disableMfa(Request $request)
    {
        $data = $request->validate(['password' => 'required|string|max:255']);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && $user->session_version === (int) $request->session()->get('account_version'), 403);
            if (! $user->totp_confirmed_at || ! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Пароль не подошёл. Код входа не отключён.']);
            }
            $user->forceFill([
                'totp_secret' => null,
                'totp_confirmed_at' => null,
                'totp_last_step' => null,
                'recovery_codes' => null,
                'mfa_reset_required' => false,
                'session_version' => $user->session_version + 1,
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'identity.mfa_disabled', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Код входа отключён. Теперь достаточно почты и пароля.');
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink($data);

        return back()->with('status', 'Если эта почта зарегистрирована, отправим ссылку для настройки пароля.');
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', 'min:12', 'max:255']]);
        $status = Password::reset($data, function (User $user, string $password) use ($data) {
            DB::transaction(function () use ($user, $password, $data) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->active && $user->email === $data['email'] && Password::tokenExists($user, $data['token']), 403);
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'session_version' => $user->session_version + 1])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                AccountInvitation::where('user_id', $user->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'identity.password_recovered', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }, 3);
        });

        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('status', 'Пароль сохранён. Войдите с новым паролем.') : back()->withErrors(['email' => 'Ссылка недействительна. Запросите новую.']);
    }
}
