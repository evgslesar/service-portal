<?php

namespace App\Actions\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactor
{
    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $user->active || ! $user->totp_secret) {
                return false;
            }
            if (preg_match('/^\d{6}$/D', $code)) {
                $step = (new Google2FA)->verifyKeyNewer($user->totp_secret, $code, $user->totp_last_step ?? 0, 1);
                if ($step !== false) {
                    $user->forceFill(['totp_last_step' => $step])->save();

                    return true;
                }
            }
            if (! $user->totp_confirmed_at) {
                return false;
            }
            $hashes = $user->recovery_codes ?? [];
            foreach ($hashes as $index => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashes[$index]);
                    $user->forceFill(['recovery_codes' => array_values($hashes)])->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function recoveryCodes(User $user): array
    {
        $codes = array_map(fn () => Str::random(16), range(1, 8));
        $user->forceFill(['recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes), 'totp_confirmed_at' => now(), 'mfa_reset_required' => false])->save();

        return $codes;
    }
}
