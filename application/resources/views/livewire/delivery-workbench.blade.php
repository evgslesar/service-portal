<div>
    <div class="page-heading"><p class="eyebrow">Письма</p><h1>Доставка писем</h1><p>Управляйте личными уведомлениями и, при наличии прав, контролируйте очередь доставки.</p></div>
    <x-errors />
    <section class="card">
        <form wire:submit="save" class="stack">
            <h2>Личные настройки</h2>
            <label class="check-row"><input type="checkbox" wire:model="email">Получать письма о доступных мне событиях</label>
            <p>Отключение писем не удаляет уведомления в портале и не отменяет работы. Приглашение для настройки доступа отправляется отдельно.</p>
            <button class="button primary" wire:loading.attr="disabled" wire:offline.attr="disabled">Сохранить настройки писем</button>
        </form>
    </section>
    @if($canManageDeliveries)
        <div class="page-heading"><p class="eyebrow">Оператор</p><h2>Очередь доставки</h2><p>Повтор относится только к письму. Работы, решения и расчёты не повторяются.</p></div>
        @unless($enabled)<p class="notice">Отправка отключена. Настройте рабочий почтовый транспорт и разрешите доставку. Журнал и очередь сохранены; запись в лог не считается отправленным письмом.</p>@endunless
        <div class="stack">
            @forelse($deliveries as $delivery)
                <section class="card" wire:key="delivery-{{ $delivery->id }}">
                    <p class="eyebrow">Письмо #{{ $delivery->id }} · {{ $delivery->account_invitation_id ? 'Приглашение' : 'Событие портала' }}</p>
                    <h2>{{ ['pending' => 'В очереди', 'processing' => 'Отправляется', 'sent' => 'Передано почтовому транспорту', 'failed' => 'Нужен повтор', 'suppressed' => 'Не отправлено: изменились права или настройки'][$delivery->status] ?? 'Нужна проверка' }}</h2>
                    <p>Попыток: {{ $delivery->attempts }} · {{ $delivery->sent_at ? 'Передано: '.$delivery->sent_at : 'Планируется: '.$delivery->available_at }}</p>
                    @if(in_array($delivery->status, ['pending', 'failed']))
                        <label for="delivery-reason-{{ $delivery->id }}">Основание повтора</label>
                        <textarea id="delivery-reason-{{ $delivery->id }}" wire:model="reasons.{{ $delivery->id }}" maxlength="5000"></textarea>
                        <button class="button secondary" wire:click="retry({{ $delivery->id }})" wire:loading.attr="disabled" wire:offline.attr="disabled">Поставить письмо на повтор</button>
                    @endif
                </section>
            @empty
                <section class="card"><h2>Очередь пуста</h2><p>Новые доступные получателям события появятся после обработки уведомлений.</p></section>
            @endforelse
        </div>
    @endif
</div>
