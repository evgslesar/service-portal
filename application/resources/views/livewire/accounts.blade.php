<div>
    <div class="page-heading heading-row">
        <div><p class="eyebrow">Администрирование · доступ</p><h1>Пользователи</h1><p class="muted">Приглашения, роли и разрешённые объекты. Все изменения сохраняются в истории.</p></div>
        <button class="button primary" wire:click="createForm">Пригласить пользователя</button>
    </div>
    <x-errors />
    @if($invitation)
        <div class="notice success stack" role="status">
            <p>Передайте эту ссылку только {{ $invitation->user->name }} через проверенный канал. Она действует до {{ $invitation->expires_at->timezone(config('portal.timezone'))->format('d.m.Y H:i') }} и используется один раз. Почтовая отправка пока не подключена.</p>
            <label for="invitation-link">Ссылка для настройки пароля</label>
            <input id="invitation-link" class="secret" readonly value="{{ $invitation->setupUrl() }}" onclick="this.select()" autocomplete="off">
        </div>
    @endif
    <div class="two-columns detail-columns">
        <section class="stack" aria-label="Список пользователей">
            <label class="stack" for="account-search">Поиск по имени или почте<input id="account-search" wire:model.live.debounce.300ms="search" type="search" maxlength="255"></label>
            @foreach($accounts as $account)
                <article class="card request-row" wire:key="account-{{ $account->id }}">
                    <div><span class="badge">{{ $account->active ? 'Активен' : 'Доступ отключён' }}</span><h2>{{ $account->name }}</h2><p class="small muted">{{ $account->email }}</p><p class="small">{{ $account->kind === 'staff' ? 'Сотрудник' : ($account->accept_work ? 'Представитель организации' : 'Контакт на объектах') }} · {{ $account->totp_confirmed_at ? 'Код входа подключён' : 'Вход по паролю' }}</p></div>
                    <button class="button secondary" wire:click="edit({{ $account->id }})">Открыть доступ</button>
                </article>
            @endforeach
            {{ $accounts->links() }}
            @if($accounts->isEmpty())<div class="card empty"><h2>Пользователи не найдены</h2><p>Измените имя или почту в поиске.</p></div>@endif
        </section>
        @if($showForm)
            <section class="card stack" aria-label="Настройка доступа">
                <h2>{{ $editing ? 'Настройка доступа' : 'Новое приглашение' }}</h2>
                <form wire:submit="save" class="stack" data-draft-form>
                    <label for="account-name">Имя</label><input id="account-name" wire:model="form.name" maxlength="255" autocomplete="off" required>
                    <label for="account-email">Почта</label><input id="account-email" wire:model="form.email" type="email" maxlength="255" autocomplete="off" required>
                    <label for="account-kind">Тип учётной записи</label>
                    <select id="account-kind" wire:model.live="form.kind" @disabled($editing)><option value="client">Клиент</option><option value="staff">Сотрудник</option></select>
                    @if(($form['kind'] ?? '') === 'staff')
                        <fieldset><legend>Роли сотрудника</legend>
                            @foreach(['dispatcher' => 'Диспетчер', 'technician' => 'Мастер / ведущий', 'finance' => 'Финансы', 'warehouse' => 'Склад и закупки', 'administrator' => 'Администратор приложения'] as $role => $label)
                                <label class="check-row"><input type="checkbox" wire:model="form.roles" value="{{ $role }}">{{ $label }}</label>
                            @endforeach
                        </fieldset>
                        <label class="check-row"><input type="checkbox" wire:model="form.permissions" value="manage_directory">Диспетчеру: управление пользователями и справочниками</label>
                        <p class="small muted">Администратор не получает доступ к заявкам без бизнес-роли. Финансы и склад пока не реализованы.</p>
                    @else
                        <label for="account-org">Организация клиента</label>
                        <select id="account-org" wire:model.live="form.organization_id" required><option value="">Выберите организацию</option>@foreach($organizations as $organization)<option value="{{ $organization->id }}">{{ $organization->name }}{{ $organization->active ? '' : ' · архив' }}</option>@endforeach</select>
                        <label class="check-row"><input type="checkbox" wire:model.live="form.accept_work">Представитель: все объекты организации и право приёмки</label>
                        @if(!($form['accept_work'] ?? false))
                            <fieldset><legend>Разрешённые объекты</legend>@forelse($sites as $site)<label class="check-row"><input type="checkbox" wire:model="form.site_ids" value="{{ $site->id }}">{{ $site->name }}{{ $site->active ? '' : ' · архив' }}</label>@empty<p class="small muted">Выберите организацию с объектами. Без разрешённых объектов контакт не увидит заявки.</p>@endforelse</fieldset>
                        @else<p class="small muted">Представитель видит все объекты выбранной организации.</p>@endif
                    @endif
                    <label class="check-row"><input type="checkbox" wire:model="form.active">Доступ активен</label>
                    <label for="account-reason">Причина изменения / приглашения</label><textarea id="account-reason" wire:model="reason" rows="3" maxlength="5000" required></textarea>
                    <p class="small muted">Сохранение отзовёт сеансы и старые приглашения. Мастеру сначала передайте незавершённые выезды и ответственность за результаты.</p>
                    <button class="button primary" wire:loading.attr="disabled">{{ $editing ? 'Сохранить доступ' : 'Создать приглашение' }}</button>
                </form>
                @if($editing)
                    <details><summary>Выдать новое приглашение</summary><p class="small muted">Предыдущее приглашение и активные сеансы будут отозваны. Подключённый код входа сохраняется. Сначала укажите причину выше.</p><button class="button secondary" wire:click="invite" wire:confirm="Выдать новую ссылку и отозвать активные сеансы?" wire:loading.attr="disabled">Создать новую ссылку</button></details>
                    @if($editing !== auth()->id())
                        <details><summary>Сбросить код входа</summary><p class="small muted">Сначала подтвердите личность через проверенный канал. Не записывайте пароли, коды или копии документов. Укажите причину выше.</p><label for="verification">Как подтверждена личность</label><textarea id="verification" wire:model="evidence" rows="3" maxlength="5000"></textarea><button class="button danger" wire:click="resetMfa" wire:confirm="Отключить код входа и отозвать все сеансы?" wire:loading.attr="disabled">Сбросить защиту</button></details>
                    @endif
                    <details><summary>История доступа</summary>@forelse($history as $event)@php($data = json_decode($event->data, true))<div class="message small"><p>{{ \Illuminate\Support\Carbon::parse($event->created_at)->timezone(config('portal.timezone'))->format('d.m.Y H:i') }} · сотрудник #{{ $event->actor_id }}</p><p class="preline">{{ $data['reason'] ?? 'Настройка входа' }}</p>@if(isset($data['identity_verification']))<p class="muted preline">Проверка личности: {{ $data['identity_verification'] }}</p>@endif</div>@empty<p class="small muted">Изменений доступа пока нет.</p>@endforelse</details>
                @endif
            </section>
        @endif
    </div>
</div>
