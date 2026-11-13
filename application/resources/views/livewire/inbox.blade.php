<div wire:poll.60s.visible>
    <div class="page-heading"><p class="eyebrow">Изменения по вашим заявкам</p><h1>Уведомления</h1><p class="muted">Здесь появляются подтверждённые изменения. Решения и отчёты открываются в заявке.</p></div>
    <div class="filters"><label for="notice-filter">Показать</label><select id="notice-filter" wire:model.live="filter"><option value="unread">Непрочитанные</option><option value="all">Все</option></select></div>
    <div class="stack">
        @forelse($notices as $notice)
            <article class="card" wire:key="notice-{{ $notice->id }}">
                <p class="eyebrow">{{ $notice->request->site->name }} · {{ $notice->created_at->timezone($notice->request->site->timezone)->format('d.m H:i') }}</p>
                @if(!$notice->read_at)<span class="badge action">Не прочитано</span>@endif
                <h2>{{ $notice->title }}</h2><p>{{ $notice->request->title }}</p>
                <div class="action-row"><button class="button primary" wire:click="open({{ $notice->id }})" wire:loading.attr="disabled">Открыть заявку</button>@if(!$notice->read_at)<button class="button secondary" wire:click="markRead({{ $notice->id }})" wire:loading.attr="disabled">Отметить прочитанным</button>@endif</div>
            </article>
        @empty
            <section class="card"><h2>{{ $filter === 'all' ? 'Уведомлений пока нет' : 'Всё прочитано' }}</h2><p>Следующие изменения появятся здесь. Текущие задачи доступны на главной.</p><a class="inline-link" href="{{ route('home') }}">Открыть задачи →</a></section>
        @endforelse
    </div>
    {{ $notices->links() }}
</div>
