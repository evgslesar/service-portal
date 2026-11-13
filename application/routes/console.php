<?php

use App\Actions\Finance\ProviderCommands;
use App\Actions\Notifications\DeliverPortalOutbox;
use App\Actions\Notifications\EmailDelivery;
use App\Integrations\YooKassa;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('portal:password {email}', function () {
    if (! app()->environment('local') || ! config('portal.demo') || ! str_ends_with(strtolower($this->argument('email')), '@example.test')) {
        $this->error('Password setup is restricted to local fictional demo accounts.');

        return 1;
    }
    $user = User::where('email', $this->argument('email'))->where('active', true)->first();
    if (! $user) {
        $this->error('No active demo account has that email.');

        return 1;
    }
    $password = $this->secret('New password (at least 12 characters)');
    $confirmation = $this->secret('Confirm password');
    if (! is_string($password) || mb_strlen($password) < 12 || $password !== $confirmation) {
        $this->error('Passwords must match and contain at least 12 characters.');

        return 1;
    }
    DB::transaction(function () use ($user, $password) {
        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $user->forceFill(['password' => $password, 'session_version' => $user->session_version + 1])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'identity.demo_password_changed', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    });
    $this->info('Demo password set. Existing sessions were revoked; MFA is unchanged.');

    return 0;
})->purpose('Set a local fictional demo account password without exposing it in command arguments');

Artisan::command('portal:deliver-notifications {--limit=100}', function (DeliverPortalOutbox $outbox) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Limit must be between 1 and 1000.');

        return 1;
    }
    $this->info('Delivered '.$outbox->execute($limit).' portal events.');

    return 0;
})->purpose('Deliver pending portal notifications idempotently');

Artisan::command('portal:deliver-email {--limit=100}', function (EmailDelivery $delivery) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Limit must be between 1 and 1000.');

        return 1;
    }
    if (! $delivery->enabled()) {
        $this->warn('Email transport is disabled or not configured.');

        return 0;
    }
    $this->info('Processed '.$delivery->execute($limit).' email deliveries.');

    return 0;
})->purpose('Send durable email deliveries after rechecking recipients and permissions');

Artisan::command('portal:reconcile-payments {--limit=100}', function (ProviderCommands $commands, YooKassa $provider) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Limit must be between 1 and 1000.');

        return 1;
    }
    if (! $provider->enabled()) {
        $this->warn('YooKassa sandbox is disabled or not configured. Live payments remain disabled.');

        return 0;
    }
    $this->info('Processed '.$commands->verifyWebhooks($limit).' webhook hints and '.$commands->execute($limit).' provider commands.');

    return 0;
})->purpose('Execute durable sandbox commands and reconcile authoritative provider state');

Schedule::command('portal:reconcile-payments')->everyMinute()->withoutOverlapping();
Schedule::command('portal:deliver-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('portal:deliver-email')->everyMinute()->withoutOverlapping();
