<x-layout>
<div class="auth-card card">
    <p class="eyebrow">Безопасный вход</p><h1>{{ $qr ? 'Подключить код входа' : 'Введите код входа' }}</h1>
    @if($qr)<p>Откройте приложение-аутентификатор на телефоне, добавьте аккаунт и отсканируйте код.</p><div class="qr">{!! $qr !!}</div><details><summary>Не получается сканировать?</summary><p>Введите этот ключ в приложении вручную:</p><code class="secret">{{ $user->totp_secret }}</code></details>@else<p>Введите текущие шесть цифр из приложения или один резервный код.</p>@endif
    <x-errors />
    <form method="post" action="{{ route('mfa') }}" class="stack">@csrf<label for="code">Код из приложения</label><input id="code" name="code" autocomplete="one-time-code" maxlength="32" required autofocus><button class="button primary">{{ $qr ? 'Подключить и продолжить' : 'Продолжить' }}</button></form>
    @if($qr)<a class="inline-link" href="{{ route('home') }}">Продолжить без кода входа</a>@endif
    <p class="small muted">Дополнительная защита необязательна. В действующем сеансе код не нужен для каждого обычного действия.</p>
    <p>Потеряли телефон? Обратитесь к администратору сервисной компании для проверки личности.</p>
    @if(config('portal.support_phone'))<a class="inline-link" href="tel:{{ config('portal.support_phone') }}">{{ config('portal.support_phone') }}</a>@endif
</div>
</x-layout>
