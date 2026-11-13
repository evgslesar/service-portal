<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function show(AccountInvitation $invitation)
    {
        return response()->view('auth.invitation', [
            'invitation' => $invitation,
            'usable' => $invitation->usable(),
        ], $invitation->usable() ? 200 : 410)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, AccountInvitation $invitation)
    {
        $data = $request->validate(['password' => 'required|string|confirmed|min:12|max:255']);
        DB::transaction(function () use ($invitation, $data) {
            DB::table('identity_locks')->where('id', 1)->lockForUpdate()->first();
            $user = User::whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
            $invitation = AccountInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $invitation->setRelation('user', $user);
            abort_unless($invitation->usable(), 410, 'Ссылка недействительна. Попросите администратора выдать новое приглашение.');
            $user->forceFill(['password' => $data['password'], 'email_verified_at' => now(), 'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
            $invitation->update(['consumed_at' => now()]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'identity.invitation_accepted', 'data' => json_encode(['invitation_id' => $invitation->id]), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return redirect()->route('login')->with('status', 'Пароль сохранён. Войдите; для сотрудников и представителей следующий шаг — настройка кода входа.');
    }
}
