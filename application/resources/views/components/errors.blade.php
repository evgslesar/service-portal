@if($errors->any())
<div class="notice error" role="alert" tabindex="-1">
    <strong>Нужно проверить</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif
