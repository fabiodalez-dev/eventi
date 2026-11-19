{{-- Una segnalazione: il testo inviato, la conversazione con l'assistenza e la risposta. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.case', ['id' => $case_id])" :subtitle="$case_status"
        :back="route('carpool.cases')" :back-label="__('carpool.web.back_cases')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @if ($body)
            <x-card :title="__('carpool.web.case_body')">
                <p class="text-sm whitespace-pre-line break-words text-ink">{{ $body }}</p>
            </x-card>
        @endif

        <x-card :title="__('carpool.web.case_messages')" data-case-messages>
            @if (count($messages) === 50)
                <div><x-button variant="ghost" size="sm" :href="request()->fullUrlWithQuery(['before' => $messages[0]['id']])">{{ __('carpool.older') }}</x-button></div>
            @endif
            <div class="flex flex-col gap-3">
                @foreach ($messages as $message)
                    <article @class(['px-4 py-3', 'ml-10 self-end bg-brand-soft text-on-brand-soft sm:ml-16' => $message['mine'], 'mr-10 self-start border border-line bg-surface-sunken text-ink sm:mr-16' => ! $message['mine']])>
                        <p class="mb-1 text-xs font-semibold opacity-75">{{ $message['mine'] ? __('carpool.web.you') : __('carpool.support') }}</p>
                        <p class="text-sm whitespace-pre-line break-words">{{ $message['body'] }}</p>
                    </article>
                @endforeach
            </div>
            <form method="post" action="{{ route('carpool.case.reply', $case_id) }}" class="flex flex-col gap-4">
                @include('carpool.partials.key')
                @include('carpool.partials.field', ['name' => 'body', 'id' => 'caso-risposta', 'label' => __('carpool.web.case_reply'), 'type' => 'textarea', 'rows' => 4, 'required' => true, 'maxlength' => 2000, 'value' => old('body')])
                <div><x-button type="submit">{{ __('carpool.send') }}</x-button></div>
            </form>
        </x-card>
    </x-service-page>
</x-layouts.app>
