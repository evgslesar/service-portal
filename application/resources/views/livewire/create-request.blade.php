<div class="narrow" data-draft-form data-draft-user="{{ auth()->id() }}" data-recover-fields="description,place,site_id">
    <x-draft-recovery />
    <a class="inline-link" href="{{ route('home') }}">← К заявкам</a>
    <div class="page-heading"><p class="eyebrow">Новая заявка</p><h1>Что случилось?</h1><p class="muted">Опишите проблему. Диспетчер определит работу и организует выезд.</p></div>
    <x-errors />
    <form wire:submit="send" class="card stack">
        @if($createdId)<div class="notice">Заявка уже получена. Осталось добавить выбранные фото или убрать неудачные загрузки. Повтор не создаст другую заявку.</div>@endif
        @if($relatedNumber)<p class="notice">Новая проблема будет связана с заявкой {{ $relatedNumber }}. Прежняя заявка не открывается заново.</p>@endif
        <fieldset @disabled($createdId) class="stack">
            <label for="site_id">Объект</label><select id="site_id" wire:model="site_id" required @disabled($relatedRequestId) aria-describedby="site-error"><option value="">Выберите объект</option>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->name }} — {{ $site->address }}</option>@endforeach</select>@error('site_id')<span id="site-error" class="field-error">{{ $message }}</span>@enderror
            @if($sites->isEmpty())<p class="notice">Доступные объекты пока не указаны. <a href="{{ route('help') }}">Свяжитесь с компанией</a>.</p>@endif
            <label for="description">Что случилось?</label><textarea id="description" wire:model="description" rows="5" maxlength="10000" required placeholder="Например: течёт труба под раковиной на втором этаже" aria-describedby="description-error"></textarea>@error('description')<span id="description-error" class="field-error">{{ $message }}</span>@enderror
            <label for="place">Где именно? <span class="muted">Необязательно</span></label><input id="place" wire:model="place" maxlength="255" placeholder="Этаж, кабинет или помещение">
        </fieldset>
        <label for="photos">Добавить фото <span class="muted">Необязательно</span></label><input id="photos" type="file" wire:model="photos" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" multiple>
        <p class="small muted">До 5 фото, каждое до 20 МБ. JPEG, PNG, WebP, HEIC и HEIF.</p>
        <div wire:loading wire:target="photos" role="status" class="notice">Фото загружаются. Не закрывайте страницу.</div>
        <div class="photo-grid">@foreach($photos as $i => $file)<div class="photo-preview" wire:key="photo-{{ $i }}">@if(in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp']))<img src="{{ $file->temporaryUrl() }}" alt="Фото к заявке">@endif<button type="button" class="text-button" wire:click="removePhoto({{ $i }})">Убрать фото</button></div>@endforeach</div>
        <p class="small muted">Выбранные фото не исчезнут без вашего решения. При ошибке можно повторить отправку или убрать фото.</p>
        <div wire:offline class="notice error" role="status">Нет связи. Текст остаётся на открытой странице. Отправьте, когда связь восстановится.</div>
        <button class="button primary" wire:loading.attr="disabled" wire:offline.attr="disabled" @disabled($sites->isEmpty())>{{ $createdId ? 'Сохранить фото и открыть заявку' : 'Отправить заявку' }} →</button>
        <span wire:loading wire:target="send" role="status">Отправляем заявку…</span>
        <p class="small muted">Портал не предназначен для экстренных обращений.</p>
    </form>
</div>
