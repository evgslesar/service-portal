<x-layout>
    <div class="auth-card card">
        <p class="eyebrow">Приглашение в сервисный кабинет</p>
        @if($usable)
            <h1>Настройте вход</h1>
            <p>{{ $invitation->user->name }}, приглашение выдано для {{ $invitation->user->email }}. Создайте пароль не короче 12 символов.</p>
            <p class="small muted">Для входа достаточно почты и пароля. Дополнительную защиту кодом можно подключить позже в профиле.</p>
            <x-errors />
            <form class="stack" method="post" action="{{ request()->fullUrl() }}">
                @csrf
                <label for="password">Новый пароль</label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="255" required>
                <label for="password_confirmation">Повторите пароль</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
                <button class="button primary">Сохранить пароль</button>
            </form>
        @else
            <h1>Нужно новое приглашение</h1>
            <p>Ссылка уже использована, отменена или истекла. Попросите администратора выдать новую.</p>
            <a class="inline-link" href="{{ route('login') }}">Перейти ко входу →</a>
        @endif
        @if(config('portal.support_email'))<p class="small">Помощь: {{ config('portal.support_email') }}</p>@endif
    </div>
</x-layout>
