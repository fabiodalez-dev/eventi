@if ($errors->any())
    <div role="alert" class="border border-line bg-surface p-4"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
