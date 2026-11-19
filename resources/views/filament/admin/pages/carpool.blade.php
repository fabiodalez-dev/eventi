@php
    use App\Enums\CarpoolCaseStatus;
    use App\Enums\Permission;
    use App\Enums\RideStatus;
    use App\Services\Carpool\CommunitySafety;
    use App\Services\Community\CommunityModeration;
    use Filament\Support\Facades\FilamentTimezone;
    use Illuminate\Support\Carbon;

    $timezone = FilamentTimezone::get();
    $at = fn ($date) => filled($date) ? Carbon::parse($date, 'UTC')->timezone($timezone)->format('d/m/Y H:i') : '—';
    $sections = \App\Filament\Admin\Pages\Carpool::SECTIONS;
    $statuses = $section === 'cases' ? CarpoolCaseStatus::cases() : ($section === 'rides' ? RideStatus::cases() : []);
@endphp
<x-filament-panels::page>
    <div class="flex min-w-0 flex-col gap-4" data-carpool-moderation>
        <x-filament::tabs :label="__('carpool.admin.section')" class="max-w-full flex-wrap">
            @foreach ($sections as $tab)
                <x-filament::tabs.item :active="$section === $tab" wire:click="$set('section', '{{ $tab }}')">
                    {{ __('carpool.admin.'.$tab) }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-end">
            <label class="flex min-w-0 flex-1 flex-col gap-2">
                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('carpool.admin.search') }}</span>
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" maxlength="80" />
                </x-filament::input.wrapper>
            </label>
            @if ($statuses !== [])
                <label class="flex flex-col gap-2 sm:w-64">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('carpool.admin.status') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="status">
                            <option value="">{{ __('carpool.admin.all') }}</option>
                            @foreach ($statuses as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            @endif
        </div>
    </div>

    @if ($case)
        {{-- Il caso aperto: chi l'ha segnalato, il testo, le azioni e la conversazione. --}}
        <x-filament::section :heading="__('carpool.admin.case').' #'.$case->id.' · '.$case->status->label()" :description="collect([$case->reporter?->name, $case->reason, $at($case->created_at)])->filter()->join(' · ')" data-carpool-case>
            <x-slot name="afterHeader">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-x-mark" wire:click="$set('selectedCase', null)">{{ __('admin.carpool_page.close_case') }}</x-filament::button>
            </x-slot>

            <div class="flex min-w-0 flex-col gap-4">
                @if (filled($case->body))
                    <p class="whitespace-pre-wrap text-sm text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $case->body }}</p>
                @endif
                @if ($case->review_snapshot)
                    <p class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $case->review_snapshot['rating'] }}/5 · {{ $case->review_snapshot['body'] }}</p>
                @endif
                @if ($case->social_type)
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('carpool.admin.social_reference', ['type' => $case->social_type, 'id' => $case->social_id]) }}</p>
                @endif
                @if ($case->ride_offer_id)
                    <x-filament::link :href="route('carpool.offer', $case->ride_offer_id)" icon="heroicon-o-arrow-top-right-on-square">{{ __('carpool.admin.ride_reference', ['id' => $case->ride_offer_id]) }}</x-filament::link>
                @endif

                <div class="flex flex-wrap gap-3">
                    @foreach (['assign', 'note', $case->closed_at ? 'reopen' : 'resolve'] as $op)
                        {{ ($this->caseAction)(['id' => $case->id, 'revision' => $case->revision, 'op' => $op]) }}
                    @endforeach
                    {{ ($this->replyCaseAction)(['id' => $case->id, 'revision' => $case->revision]) }}
                    @if ($case->ride_request_id)
                        {{ ($this->freezeChatAction)(['id' => $case->id, 'freeze' => true]) }}
                        {{ ($this->freezeChatAction)(['id' => $case->id, 'freeze' => false]) }}
                    @endif
                </div>

                @if ($canRetention)
                    <div class="flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('carpool.admin.hold_hint') }} {{ $case->hold_until ? $at($case->hold_until) : '—' }}</p>
                        <div class="flex flex-wrap gap-3">
                            @foreach (['hold', 'release'] as $op)
                                {{ ($this->holdCaseAction)(['id' => $case->id, 'revision' => $case->revision, 'op' => $op]) }}
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($canSensitive)
                    {{-- Le evidenze si aprono in una pagina a parte, registrata: è un modulo vero, con CSRF. --}}
                    <form method="post" action="{{ route('carpool.evidence', $case) }}" class="flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
                        @csrf
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('carpool.admin.evidence') }}</p>
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('carpool.admin.access_logged') }}</p>
                        <label class="flex flex-col gap-2">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('carpool.admin.reason') }}</span>
                            <x-filament::input.wrapper>
                                <textarea name="reason" required minlength="5" maxlength="1000" rows="3" class="fi-input block w-full resize-y border-none bg-transparent px-3 py-1.5 text-base text-gray-950 outline-none sm:text-sm dark:text-white"></textarea>
                            </x-filament::input.wrapper>
                        </label>
                        <label class="flex flex-col gap-2 sm:max-w-sm">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('carpool.admin.password') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input name="password" type="password" required autocomplete="current-password" />
                            </x-filament::input.wrapper>
                        </label>
                        <div class="flex flex-wrap gap-3">
                            <x-filament::button type="submit" size="sm">{{ __('carpool.admin.inspect') }}</x-filament::button>
                            @if (app(CommunitySafety::class)->staff(auth()->user(), Permission::ExportCommunityEvidence, true))
                                <x-filament::button type="submit" name="export" value="1" color="gray" size="sm">{{ __('carpool.admin.export') }}</x-filament::button>
                            @endif
                        </div>
                    </form>
                @endif

                @if ($case->messages->isNotEmpty())
                    <ul class="divide-y divide-gray-100 border-t border-gray-200 dark:divide-white/10 dark:border-white/10">
                        @foreach ($case->messages as $message)
                            <li class="flex flex-col gap-1 py-3">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $message->author?->name }} · {{ $at($message->created_at) }} · {{ $message->internal ? __('carpool.admin.internal') : ($message->recipient?->name ?? __('carpool.admin.to_staff')) }}</p>
                                <p class="whitespace-pre-wrap text-sm text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $message->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-filament::section>
    @endif

    @if ($items->isEmpty())
        <x-filament::empty-state icon="heroicon-o-inbox" :heading="__('admin.carpool_page.empty')" :description="$search !== '' ? __('admin.community_page.empty_search') : null" />
    @else
        <x-filament::section>
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($items as $item)
                    <li class="flex min-w-0 flex-col gap-3 py-4 first:pt-0 last:pb-0" wire:key="{{ $section }}-{{ $item->id }}">
                        @if ($section === 'reviews')
                            <div class="flex min-w-0 flex-col gap-1">
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item->user?->name }} → {{ $item->driver?->name }} · {{ $item->rating }}/5</p>
                                <p class="whitespace-pre-wrap text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $item->body }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('carpool.reviews.confirmed') }}: {{ $at($item->rideRequest->passenger_confirmed_at) }} · #{{ $item->ride_request_id }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3">{{ ($this->moderateReviewAction)(['id' => $item->id, 'visible' => $item->status->value === 'hidden']) }}</div>
                        @elseif ($section === 'feedback')
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item->user?->name }} · {{ $item->kind->label() }} · #{{ $item->ride_request_id }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $at($item->created_at) }}</p>
                            <p class="whitespace-pre-wrap text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $item->body }}</p>
                        @elseif ($section === 'rides')
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-gray-950 [overflow-wrap:anywhere] dark:text-white">#{{ $item->id }} · {{ $item->snapshot['title'] ?? '' }} · {{ $item->leg->label() }}</p>
                                    <x-filament::badge color="gray">{{ $item->status->label() }}</x-filament::badge>
                                </div>
                                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $item->driver?->name }} · {{ $item->zone }} · {{ $at($item->departure_at) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('carpool.admin.capacity_summary', ['capacity' => $item->capacity, 'count' => $item->requests_count]) }}</p>
                            </div>
                            @if (in_array($item->status, [RideStatus::Draft, RideStatus::Open, RideStatus::Closed], true))
                                <div class="flex flex-wrap gap-3">{{ ($this->cancelRideAction)(['id' => $item->id]) }}</div>
                            @endif
                        @elseif ($section === 'users')
                            <div class="flex min-w-0 flex-col gap-1">
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item->name }} #{{ $item->id }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('carpool.admin.email_verified') }}: {{ $at($item->email_verified_at) }} · {{ __('carpool.admin.whatsapp_verified') }}: {{ $at($item->whatsapp_verified_at) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                @foreach (['social' => 'social_suspended_at', 'carpool' => 'carpool_suspended_at', 'global' => 'community_suspended_at'] as $scope => $field)
                                    @if (($scope !== 'global' || auth()->user()->hasPermissionTo(Permission::ManageUsers->value)) && ($item->{$field} || CommunityModeration::outranks(auth()->user(), $item)))
                                        {{ ($this->restrictUserAction)(['id' => $item->id, 'scope' => $scope, 'suspend' => ! $item->{$field}]) }}
                                    @endif
                                @endforeach
                            </div>
                        @elseif ($section === 'audit')
                            <p class="text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">#{{ $item->id }} · {{ $at($item->created_at) }} · {{ $item->action }} · {{ $item->subject_type }} #{{ $item->subject_id }} · {{ $item->actor?->name ?? __('carpool.admin.system') }}</p>
                        @elseif ($section === 'deliveries')
                            <p class="text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">#{{ $item->id }} · {{ __('carpool.admin.delivery_summary', ['user' => $item->user_id, 'attempts' => $item->attempts]) }} · {{ $item->last_error }}</p>
                            <div class="flex flex-wrap gap-3">{{ ($this->retryDeliveryAction)(['id' => $item->id]) }}</div>
                        @else
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">#{{ $item->id }} · {{ $item->reporter?->name }}</p>
                                    <x-filament::badge color="gray">{{ $item->status->label() }}</x-filament::badge>
                                </div>
                                <p class="text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $at($item->created_at) }} · {{ $item->assignee?->name ?? __('carpool.admin.unassigned') }} · {{ $item->reason }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <x-filament::button color="gray" size="sm" icon="heroicon-o-folder-open" wire:click="$set('selectedCase', {{ $item->id }})">{{ __('carpool.admin.open_case') }}</x-filament::button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::pagination :paginator="$items" />
    @endif
</x-filament-panels::page>
