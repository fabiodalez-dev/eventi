<x-filament-panels::page>
    @php
        $overview = app(\App\Services\Operations\SchedulerOverview::class);
        $installed = $overview->installed();
        $cron = $overview->cron();
        $tasks = $overview->tasks();
        $present = fn (bool $found): string => __($found ? 'admin.scheduler_page.in_crontab' : 'admin.scheduler_page.not_in_crontab');
        $code = 'rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-white/10';
    @endphp

    <p class="max-w-prose text-sm text-gray-700 dark:text-gray-300">{{ __('admin.scheduler_page.intro') }}</p>

    <x-filament::section :heading="__('admin.scheduler_page.cron_heading')">
        <div class="flex min-w-0 flex-col gap-3 text-sm text-gray-950 dark:text-white">
            @if ($installed['readable'])
                <p>{{ __('admin.scheduler_page.scheduler') }}: <strong>{{ $present($installed['scheduler']) }}</strong>. {{ __('admin.scheduler_page.worker') }}: <strong>{{ $present($installed['worker']) }}</strong>.</p>
            @else
                <p>{{ __('admin.scheduler_page.unreadable_before') }} <code class="{{ $code }}">crontab -l</code>. {{ __('admin.scheduler_page.unreadable_after') }}</p>
            @endif
            <p>{{ __('admin.scheduler_page.every_minute_before') }} <code class="{{ $code }}">flock</code> {{ __('admin.scheduler_page.every_minute_after') }}</p>

            @foreach ($cron as $kind => $line)
                <div class="flex min-w-0 flex-col gap-2">
                    <h3 class="font-semibold">{{ __('admin.scheduler_page.cron.'.($kind === 'scheduler' ? 'scheduler' : 'worker')) }}</h3>
                    <pre class="whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs [overflow-wrap:anywhere] dark:border-white/10 dark:bg-white/5"><code>{{ $line }}</code></pre>
                </div>
            @endforeach

            <p>
                {{ __('admin.scheduler_page.queue', ['queue' => config('queue.default')]) }}
                <code class="{{ $code }}">php artisan schedule:list</code>, <code class="{{ $code }}">php artisan queue:failed</code>, <code class="{{ $code }}">php artisan health:check</code>.
                {{ __('admin.scheduler_page.queue_after') }}
            </p>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.scheduler_page.social_heading')">
        <div class="flex min-w-0 flex-col gap-3 text-sm text-gray-950 dark:text-white">
            <p>
                {{ __('admin.scheduler_page.social_before') }}
                <x-filament::link :href="\App\Filament\Admin\Pages\SocialSettings::getUrl()">{{ __('admin.scheduler_page.social_link') }}</x-filament::link>
                {{ __('admin.scheduler_page.social_after') }}
            </p>
            <p>{{ __('admin.scheduler_page.social_timing') }}</p>
            @forelse (\App\Models\SocialConnection::all() as $connection)
                <p>
                    {{ __('admin.scheduler_page.telegram', ['state' => __($connection->telegram_verified_at ? 'admin.scheduler_page.verified' : 'admin.scheduler_page.to_verify')]) }}
                    {{ __('admin.scheduler_page.connection', ['city' => $connection->city_id, 'state' => $connection->verified_at ? __('admin.scheduler_page.verified_at', ['date' => $connection->verified_at->copy()->timezone(\Filament\Support\Facades\FilamentTimezone::get())->format('d/m/Y H:i')]) : __('admin.scheduler_page.to_verify')]) }}
                    {{ __($connection->instagram_enabled ? 'admin.scheduler_page.instagram_on' : 'admin.scheduler_page.instagram_off') }}
                    {{ $connection->automatic ? __('admin.scheduler_page.autopost_on', ['time' => $connection->publish_time]) : __('admin.scheduler_page.autopost_off') }}
                </p>
            @empty
                <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle" role="status" :heading="__('admin.scheduler_page.no_social')" />
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.scheduler_page.tasks_heading')" :description="__('admin.scheduler_page.tasks_description')">
        {{-- Una card per attività invece di una tabella a quattro colonne che
             sul telefono scorreva di lato. --}}
        <ul class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($tasks as $task)
                <li class="flex min-w-0 flex-col gap-2 py-4 text-sm first:pt-0 last:pb-0">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ $task['name'] }}</p>
                    <p class="text-gray-700 dark:text-gray-300">{{ $task['explanation'] }}</p>
                    <code class="block text-xs text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $task['command'] }}</code>
                    <dl class="flex flex-col gap-1 text-gray-700 sm:flex-row sm:flex-wrap sm:gap-x-6 dark:text-gray-300">
                        <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scheduler_page.expression') }}</dt><dd><code class="{{ $code }}">{{ $task['expression'] }}</code></dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scheduler_page.next') }}</dt><dd>{{ $task['next_due_date'] }}</dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scheduler_page.last') }}</dt><dd>{{ $task['last_finished'] ?? __('admin.scheduler_page.never_finished') }}</dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scheduler_page.failed') }}</dt><dd>{{ $task['last_failed'] ?? __('admin.scheduler_page.never_failed') }}</dd></div>
                    </dl>
                    @unless ($task['monitored'])
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scheduler_page.unmonitored') }}</p>
                    @endunless
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
