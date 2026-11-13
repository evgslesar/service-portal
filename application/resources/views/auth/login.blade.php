<x-layout>
<div class="auth-card card">
    <p class="eyebrow">Портал обслуживания помещений</p><h1>Войти в кабинет</h1>
    <p class="muted">Заявки, выезды и результаты работ — в одном месте.</p>
    <x-errors />
    <form action="{{ route('login') }}" method="post" class="stack">@csrf
        <label for="email">Электронная почта</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Пароль</label><input id="password" type="password" name="password" autocomplete="current-password" required>
        <button class="button primary">Войти</button>
    </form>
    <a class="inline-link" href="{{ route('password.request') }}">Не помню пароль / первое приглашение</a>
    <p class="small muted">Аккаунт создаёт сервисная компания. Публичной регистрации нет.</p>
    @if(config('portal.demo'))<div class="notice">Демонстрационные аккаунты: client@example.test, contact@example.test, dispatcher@example.test, technician@example.test. Пароль настраивается локально командой portal:password.</div>@endif
</div>
</x-layout>
