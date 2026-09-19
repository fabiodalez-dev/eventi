<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <label>{{ __('carpool.admin.section') }}<select wire:model.live="section" class="block rounded-lg border border-gray-300 bg-white p-3 text-gray-950">@foreach(['cases', 'rides', 'reviews', 'feedback', 'users', 'audit', 'deliveries'] as $tab)<option value="{{ $tab }}">{{ __('carpool.admin.'.$tab) }}</option>@endforeach</select></label>
        <label>{{ __('carpool.admin.search') }}<input type="search" wire:model.live.debounce.400ms="search" maxlength="80" class="block rounded-lg border border-gray-300 bg-white p-3 text-gray-950"></label>
        @if(in_array($section, ['cases', 'rides']))<label>{{ __('carpool.admin.status') }}<select wire:model.live="status" class="block rounded-lg border border-gray-300 bg-white p-3 text-gray-950"><option value="">{{ __('carpool.admin.all') }}</option>@foreach($section === 'cases' ? \App\Enums\CarpoolCaseStatus::cases() : \App\Enums\RideStatus::cases() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></label>@endif
    </div>
    <label class="block">{{ __('carpool.admin.reason') }}<textarea wire:model="reason" minlength="5" maxlength="1000" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 bg-white p-3 text-gray-950"></textarea>@error('reason')<span class="text-danger-600">{{ $message }}</span>@enderror</label>
    <p class="text-sm">{{ __('carpool.admin.reason_hint') }}</p>
    @if($case)
    <section class="space-y-4 rounded-xl border border-gray-300 p-5 dark:border-gray-700">
        <h2 class="text-xl font-bold">{{ __('carpool.admin.case') }} #{{ $case->id }} · {{ $case->status->label() }}</h2>
        <p>{{ $case->reporter?->name }} · {{ $case->reason }} · {{ $case->created_at }}</p>
        <p class="whitespace-pre-wrap">{{ $case->body }}</p>
        @if($case->review_snapshot)<p class="whitespace-pre-wrap">{{ $case->review_snapshot['rating'] }}/5 · {{ $case->review_snapshot['body'] }}</p>@endif
        @if($case->social_type)<p>{{ __('carpool.admin.social_reference', ['type' => $case->social_type, 'id' => $case->social_id]) }}</p>@endif
        @if($case->ride_offer_id)<a class="underline" href="{{ route('carpool.offer', $case->ride_offer_id) }}">{{ __('carpool.admin.ride_reference', ['id' => $case->ride_offer_id]) }}</a>@endif
        <div class="flex flex-wrap gap-3">@foreach(['assign', 'note', $case->closed_at ? 'reopen' : 'resolve'] as $action)<x-filament::button color="gray" wire:click="manageCase({{ $case->id }}, {{ $case->revision }}, '{{ $action }}')">{{ __('carpool.admin.'.$action) }}</x-filament::button>@endforeach</div>
        <div class="flex flex-wrap items-end gap-3"><label>{{ __('carpool.admin.recipient') }}<select wire:model="recipient" class="block rounded-lg border border-gray-300 bg-white p-3 text-gray-950"><option value="">{{ __('carpool.admin.choose_recipient') }}</option>@foreach(collect([$case->reporter, $case->offer?->driver, $case->rideRequest?->user])->filter()->unique('id') as $person)<option value="{{ $person->id }}">{{ $person->name }} #{{ $person->id }}</option>@endforeach</select></label><x-filament::button wire:click="manageCase({{ $case->id }}, {{ $case->revision }}, 'reply')">{{ __('carpool.admin.reply') }}</x-filament::button></div>
        @if($case->ride_request_id)<div class="flex gap-3"><x-filament::button color="warning" wire:click="freezeChat({{ $case->id }}, true)">{{ __('carpool.admin.freeze_chat') }}</x-filament::button><x-filament::button color="gray" wire:click="freezeChat({{ $case->id }}, false)">{{ __('carpool.admin.unfreeze_chat') }}</x-filament::button></div>@endif
        @if($canRetention)
        <div class="space-y-3 border-t border-gray-300 pt-4"><p>{{ __('carpool.admin.hold_hint') }} @if($case->hold_until){{ $case->hold_until }}@endif</p><label>{{ __('carpool.admin.password') }}<input type="password" autocomplete="current-password" wire:model="password" class="block rounded-lg border border-gray-300 bg-white p-3 text-gray-950"></label>@error('password')<p class="text-danger-600">{{ $message }}</p>@enderror<div class="flex gap-3">@foreach(['hold', 'release'] as $action)<x-filament::button color="gray" wire:click="manageCase({{ $case->id }}, {{ $case->revision }}, '{{ $action }}')">{{ __('carpool.admin.'.$action) }}</x-filament::button>@endforeach</div></div>
        @endif
        @if($canSensitive)
        <form method="post" action="{{ route('carpool.evidence', $case) }}" class="space-y-3 border-t border-gray-300 pt-4">@csrf<p>{{ __('carpool.admin.access_logged') }}</p><label class="block">{{ __('carpool.admin.reason') }}<textarea name="reason" required minlength="5" maxlength="1000" class="block w-full rounded-lg border border-gray-300 bg-white p-3 text-gray-950"></textarea></label><label class="block">{{ __('carpool.admin.password') }}<input name="password" type="password" required autocomplete="current-password" class="block rounded-lg border border-gray-300 bg-white p-3 text-gray-950"></label><div class="flex gap-3"><x-filament::button type="submit">{{ __('carpool.admin.inspect') }}</x-filament::button>@if(app(\App\Services\Carpool\CommunitySafety::class)->staff(auth()->user(), \App\Enums\Permission::ExportCommunityEvidence, true))<x-filament::button type="submit" name="export" value="1" color="gray">{{ __('carpool.admin.export') }}</x-filament::button>@endif</div></form>
        @endif
        <div class="divide-y divide-gray-200">@foreach($case->messages as $message)<article class="py-3"><p class="text-sm">{{ $message->author?->name }} · {{ $message->created_at }} · {{ $message->internal ? __('carpool.admin.internal') : ($message->recipient?->name ?? __('carpool.admin.to_staff')) }}</p><p class="whitespace-pre-wrap">{{ $message->body }}</p></article>@endforeach</div>
    </section>
    @endif
    <div class="divide-y divide-gray-200 dark:divide-gray-700">
    @foreach($items as $item)
        <article class="space-y-3 py-5" wire:key="{{ $section }}-{{ $item->id }}">
        @if($section === 'reviews')
            <h2 class="font-bold">{{ $item->user?->name }} → {{ $item->driver?->name }} · {{ $item->rating }}/5</h2><p class="whitespace-pre-wrap">{{ $item->body }}</p><p>{{ __('carpool.reviews.confirmed') }}: {{ $item->rideRequest->passenger_confirmed_at }} · #{{ $item->ride_request_id }}</p>
            <x-filament::button color="gray" wire:click="moderateReview({{ $item->id }}, {{ $item->status->value === 'hidden' ? 'true' : 'false' }})">{{ __($item->status->value === 'hidden' ? 'carpool.reviews.restore' : 'carpool.reviews.hide') }}</x-filament::button>
        @elseif($section === 'feedback')
            <h2 class="font-bold">{{ $item->user?->name }} · {{ $item->kind->label() }} · #{{ $item->ride_request_id }}</h2><p>{{ $item->created_at }}</p><p class="whitespace-pre-wrap">{{ $item->body }}</p>
        @elseif($section === 'rides')
            <h2 class="font-bold">#{{ $item->id }} · {{ $item->snapshot['title'] ?? '' }} · {{ $item->leg->label() }}</h2><p>{{ $item->driver?->name }} · {{ $item->zone }} · {{ $item->departure_at }} · {{ $item->status->label() }}</p><p>{{ __('carpool.admin.capacity_summary', ['capacity' => $item->capacity, 'count' => $item->requests_count]) }}</p>
            @if(in_array($item->status, [\App\Enums\RideStatus::Draft, \App\Enums\RideStatus::Open, \App\Enums\RideStatus::Closed]))<x-filament::button color="danger" wire:confirm="{{ __('carpool.admin.cancel_confirm') }}" wire:click="cancelRide({{ $item->id }})">{{ __('carpool.admin.cancel_ride') }}</x-filament::button>@endif
        @elseif($section === 'users')
            <h2 class="font-bold">{{ $item->name }} #{{ $item->id }}</h2><p>{{ __('carpool.admin.email_verified') }}: {{ $item->email_verified_at ?? '—' }} · {{ __('carpool.admin.whatsapp_verified') }}: {{ $item->whatsapp_verified_at ?? '—' }}</p>
            <div class="flex flex-wrap gap-3">@foreach(['social' => 'social_suspended_at', 'carpool' => 'carpool_suspended_at', 'global' => 'community_suspended_at'] as $scope => $field)@if($scope !== 'global' || auth()->user()->hasPermissionTo(\App\Enums\Permission::ManageUsers->value))<x-filament::button color="{{ $item->{$field} ? 'gray' : 'warning' }}" wire:click="restrict({{ $item->id }}, '{{ $scope }}', {{ $item->{$field} ? 'false' : 'true' }})">{{ __('carpool.admin.'.($item->{$field} ? 'restore_' : 'suspend_').$scope) }}</x-filament::button>@endif @endforeach</div>
        @elseif($section === 'audit')
            <p>#{{ $item->id }} · {{ $item->created_at }} · {{ $item->action }} · {{ $item->subject_type }} #{{ $item->subject_id }} · {{ $item->actor?->name ?? __('carpool.admin.system') }}</p>
        @elseif($section === 'deliveries')
            <p>#{{ $item->id }} · {{ __('carpool.admin.delivery_summary', ['user' => $item->user_id, 'attempts' => $item->attempts]) }} · {{ $item->last_error }}</p><x-filament::button color="gray" wire:click="retryDelivery({{ $item->id }})">{{ __('carpool.admin.retry') }}</x-filament::button>
        @else
            <h2 class="font-bold">#{{ $item->id }} · {{ $item->reporter?->name }} · {{ $item->status->label() }}</h2><p>{{ $item->created_at }} · {{ $item->assignee?->name ?? __('carpool.admin.unassigned') }} · {{ $item->reason }}</p><x-filament::button color="gray" wire:click="$set('selectedCase', {{ $item->id }})">{{ __('carpool.admin.open_case') }}</x-filament::button>
        @endif
        </article>
    @endforeach
    </div>
    {{ $items->links() }}
</x-filament-panels::page>
