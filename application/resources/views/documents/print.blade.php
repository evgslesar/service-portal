<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $number }} · {{ $title }}</title>
    <style>
        body{font:16px/1.5 system-ui,sans-serif;color:#173557;margin:0;background:#eef3f7}main{max-width:900px;margin:24px auto;padding:32px;background:white}h1{line-height:1.2}p,td{overflow-wrap:anywhere}.preline{white-space:pre-line}table{border-collapse:collapse;width:100%;margin:24px 0}th,td{text-align:left;padding:10px;border-bottom:1px solid #ccd6df}.toolbar{display:flex;gap:16px;align-items:center;flex-wrap:wrap}button,a{font:inherit;color:inherit}button{padding:12px 20px;cursor:pointer}.contribution{border-top:1px solid #ccd6df;margin-top:24px;padding-top:16px}.photos{display:flex;gap:12px;flex-wrap:wrap}.photos img{width:180px;max-height:200px;object-fit:contain}.muted{color:#52677a}@media(max-width:600px){main{margin:0;padding:16px}table{font-size:13px}th,td{padding:6px}}@media print{body{background:white;color:black}main{margin:0;padding:0;max-width:none}.toolbar{display:none}.contribution{break-inside:avoid}thead{display:table-header-group}a{text-decoration:none}img{break-inside:avoid}@page{margin:18mm}}
    </style>
</head>
<body><main>
    <nav class="toolbar" aria-label="Действия с документом"><a href="{{ route('requests.show', $record->id) }}">← К заявке</a><button type="button" onclick="window.print()">Печать / сохранить PDF</button><span class="muted">В окне печати выберите «Сохранить как PDF».</span></nav>
    <h1>{{ $title }}</h1><p>{{ $number }} · {{ $snapshot?->created_at ?? $document->created_at }}</p>
    <p class="muted">Заявка {{ $record->number }} · {{ $record->site->name }} · {{ $record->site->address }}</p>
    @if($snapshot)
        <p class="muted">Неизменяемый отчёт, переданный клиенту. Это не кассовый чек и не отдельный акт приёмки.</p>
        <p class="preline">{{ $snapshot->summary }}</p>
        @foreach($snapshot->contributions as $contribution)
            <section class="contribution"><h2>{{ $contribution['technician'] }}</h2>
                @if(($contribution['status'] ?? 'completed') === 'interrupted')<p>Выезд прерван · {{ $contribution['interruption_reason'] ?? '' }}</p>@endif
                @if($contribution['continues_visit_id'] ?? null)<p>Продолжение выезда #{{ $contribution['continues_visit_id'] }}</p>@endif
                <p class="preline">{{ $contribution['report'] }}</p><p>Фактическое время: {{ $contribution['labor_minutes'] }} мин.</p>
                <ul>@foreach($contribution['checklist'] as $item)<li>{{ $item['done'] ? '✓' : '—' }} {{ $item['text'] }}</li>@endforeach</ul>
                @foreach($contribution['materials'] ?? [] as $material)<p>{{ $material['name'] }}: {{ $material['quantity'] }} {{ $material['unit'] }}</p>@endforeach
                <div class="photos">@foreach($contribution['attachment_ids'] as $photo)<img src="{{ route('photos.show', $photo) }}" alt="Фото результата работ">@endforeach</div>
            </section>
        @endforeach
    @else
        <p class="preline">{{ $header['seller'] ?? '' }}</p><p>Покупатель: {{ $header['buyer'] ?? '' }}</p><p>{{ $header['tax'] ?? '' }}</p>
        @if(isset($document->reason))<p class="preline">Основание: {{ $document->reason }}</p>@endif
        @if(isset($document->due_on))<p>Срок оплаты: {{ $document->due_on }}</p>@endif
        @if($lines)
            <table><thead><tr><th>Работа / материал</th><th>Количество</th><th>Цена, ₽</th><th>Сумма, ₽</th></tr></thead><tbody>
                @foreach($lines as $line)<tr><td>{{ $line['description'] }}</td><td>{{ \App\Support\Fixed::display($line['quantity_milli']) }} {{ $line['unit'] }}</td><td>{{ \App\Support\Fixed::display($line['rate_kopecks'], 2) }}</td><td>{{ \App\Support\Fixed::display($line['total_kopecks'], 2) }}</td></tr>@endforeach
            </tbody></table>
        @else
            <p>Документ по основанию {{ $document->basis }}. Распределение исходной суммы:</p>
            <ul>@foreach(json_decode($document->shares, true) as $packageId => $amount)<li>Объём #{{ $packageId }}: {{ \App\Support\Fixed::display($amount, 2) }} ₽</li>@endforeach</ul>
        @endif
        <h2>Итого по документу: {{ \App\Support\Fixed::display($document->total_kopecks, 2) }} ₽</h2>
        <p class="muted">Исходные реквизиты и сумма сохраняются. Решения, поступления, зачёты и исправления отражаются отдельно в разделе расчётов. Этот документ не подтверждает оплату и не является кассовым чеком.</p>
    @endif
</main></body></html>
