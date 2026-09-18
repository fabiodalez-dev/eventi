<nav aria-label="{{ __('carpool.title') }}" class="mb-8 flex flex-wrap gap-x-6 gap-y-2 border-b border-line pb-4 text-sm font-semibold">
    <a href="{{ route('carpool.index') }}" class="inline-flex min-h-12 items-center gap-2">{{ __('carpool.mine') }} <span data-community-count="pending" hidden></span></a>
    <a href="{{ route('carpool.chats') }}" class="inline-flex min-h-12 items-center gap-2">{{ __('carpool.messages') }} <span data-community-count="conversations" hidden></span></a>
    <a href="{{ route('community.inbox') }}" class="inline-flex min-h-12 items-center gap-2">{{ __('community.inbox') }} <span data-community-count="unread" hidden></span></a>
    <a href="{{ route('carpool.cases') }}" class="inline-flex min-h-12 items-center">{{ __('carpool.support') }}</a>
</nav>
@if(session('status'))<p role="status" class="mb-6 border border-line p-4">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="mb-6 border border-line p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
