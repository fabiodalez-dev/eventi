<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

final class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $local = $this->app->environment('local');

        Telescope::filter(static fn (IncomingEntry $entry): bool => ! request()->is('passaggi*', 'api/v1/carpool*', 'admin-community*', 'admin/carpool*', 'livewire*') && ! preg_match('~/(?:passaggi|api/v1/carpool|admin-community|admin/carpool)(?:/|$)~', (string) ($entry->content['uri'] ?? '')) && ! str_contains((string) ($entry->content['uri'] ?? ''), '/il-mio-calendario/google/callback') && ! str_contains((string) ($entry->content['uri'] ?? ''), '/social/meta/')
            && ! str_contains((string) ($entry->content['uri'] ?? $entry->content['url'] ?? ''), 'graph.facebook.com')
            && ! str_contains((string) ($entry->content['uri'] ?? $entry->content['url'] ?? ''), 'kapso.ai')
            && ! str_contains((string) ($entry->content['uri'] ?? ''), 'whatsapp')
            && ! str_contains((string) ($entry->content['uri'] ?? ''), 'verifica-whatsapp')
            && ! str_contains((string) ($entry->content['uri'] ?? $entry->content['url'] ?? ''), 'api.telegram.org') && ($local
            || $entry->isReportableException()
            || $entry->isFailedRequest()
            || $entry->isFailedJob()
            || $entry->isScheduledTask()
            || $entry->hasMonitoredTag()));

        /* Credenziali e token non devono finire nell'osservabilita', neppure
           sul computer di sviluppo: un dump locale viene condiviso piu'
           facilmente di quanto sembri. */
        Telescope::hideRequestParameters([
            '_token',
            'body', 'note', 'reason', 'zone', 'accessibility_note', 'stops',
            'phone',
            'password',
            'password_confirmation',
            'token',
            'push_token',
            'access_token',
            'app_secret',
            'telegram_bot_token',
            'client_secret',
            'fb_exchange_token',
            'meta_oauth',
            'meta_pages',
            'refresh_token',
            'code',
            'state',
            'google_calendar_oauth',
            // Livewire form updates can include the Social token in nested snapshots.
            'components',
        ]);

        Telescope::hideRequestHeaders([
            'x-api-key',
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'x-metric-token',
        ]);
        Telescope::hideResponseParameters(['components']);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', static fn (?User $user): bool => $user?->isEditorialStaff() === true);
    }
}
