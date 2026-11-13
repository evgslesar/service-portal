<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light"><title>{{ config('portal.company') }} · Портал обслуживания</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<a class="skip" href="#main">К содержимому</a>
<header class="topbar">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark" aria-hidden="true">＋</span><span>СЕРВИС<span class="brand-sub">{{ config('portal.company') }}</span></span></a>
    <div class="topbar-end">@if(! config('portal.live_payments'))<span class="environment">Разработка · реальные платежи выключены</span>@endif @auth @if(!auth()->user()->requiresMfa() || session('mfa_passed'))<a class="text-button" href="{{ route('notifications') }}">Уведомления</a>@endif <span class="user-name">{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}" data-logout-form>@csrf<button class="text-button">Выйти</button></form>@endauth</div>
</header>
@php($ready = auth()->check() && (!auth()->user()->requiresMfa() || session('mfa_passed')))
<div class="app-shell {{ $ready ? '' : 'auth-shell' }}">
    @if($ready)
    <aside class="sidebar">
        <p class="eyebrow">Рабочее место</p>
        <p class="workspace-name">{{ auth()->user()->hasRole('technician') && request('workspace') !== 'dispatch' ? 'Мастер' : (auth()->user()->kind === 'client' ? 'Клиент' : (request()->routeIs('accounts') || !auth()->user()->hasRole('dispatcher') ? 'Администрирование' : 'Диспетчер')) }}</p>
        <nav aria-label="Основная навигация">
            <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home') && !request('section')])><span aria-hidden="true">◷</span>{{ auth()->user()->hasRole('technician') ? 'Задания' : 'Главная' }}</a>
            <a href="{{ route('home', ['section' => 'history', 'workspace' => request('workspace')]) }}" @class(['active' => request('section') === 'history'])><span aria-hidden="true">☷</span>История</a>
            <a href="{{ route('home', ['section' => 'sites', 'workspace' => request('workspace')]) }}" @class(['active' => request('section') === 'sites'])><span aria-hidden="true">⌂</span>Объекты</a>
            @if(auth()->user()->canManageUsers())<a href="{{ route('accounts') }}" @class(['active' => request()->routeIs('accounts')])><span aria-hidden="true">⚿</span>Пользователи</a>@endif
            @if(auth()->user()->hasRole('warehouse'))<a href="{{ route('stock') }}" @class(['active' => request()->routeIs('stock')])>Склад и закупки</a>@endif
            @if(auth()->user()->canManageUsers() || auth()->user()->hasRole('finance'))<a href="{{ route('delivery') }}" @class(['active' => request()->routeIs('delivery')])>Доставка писем</a>@endif
            <a href="{{ route('help') }}" @class(['active' => request()->routeIs('help')])><span aria-hidden="true">?</span>Помощь</a>
        </nav>
        @if(auth()->user()->hasRole('technician') && auth()->user()->hasRole('dispatcher'))<a class="workspace-switch" href="{{ route('home', ['workspace' => request('workspace') === 'dispatch' ? '' : 'dispatch']) }}">Сменить рабочее место →</a>@endif
        <div class="sidebar-note">Каждый выезд.<br>Один понятный следующий шаг.</div>
    </aside>
    @endif
    <main id="main" tabindex="-1">
        @if(session('status'))<div role="status" class="notice success">{{ session('status') }}</div>@endif
        {{ $slot }}
    </main>
</div>
@livewireScripts
</body>
</html>
