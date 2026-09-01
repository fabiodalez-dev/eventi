@extends('installer.layout')

@section('content')
    <h2>{{ __('installer.done.summary') }}</h2>
    <dl class="summary">
        <div>
            <dt>{{ __('installer.done.labels.app_name') }}</dt>
            <dd>{{ $summary['app_name'] ?? '' }}</dd>
        </div>
        <div>
            <dt>{{ __('installer.done.labels.app_url') }}</dt>
            <dd>{{ $summary['app_url'] ?? '' }}</dd>
        </div>
        <div>
            <dt>{{ __('installer.done.labels.database') }}</dt>
            <dd>{{ $summary['database'] ?? '' }}</dd>
        </div>
        <div>
            <dt>{{ __('installer.done.labels.city') }}</dt>
            <dd>{{ $summary['city_name'] ?? '' }} ({{ $summary['city_slug'] ?? '' }})</dd>
        </div>
        <div>
            <dt>{{ __('installer.done.labels.admin_email') }}</dt>
            <dd>{{ $summary['admin_email'] ?? '' }}</dd>
        </div>
        <div>
            <dt>{{ __('installer.done.labels.mail') }}</dt>
            <dd>{{ __('installer.done.mail.'.($summary['mail_mailer'] ?? 'log')) }}</dd>
        </div>
        <div>
            <dt>{{ __('installer.done.labels.image_driver') }}</dt>
            <dd>{{ $summary['image_driver'] ?? '' }}</dd>
        </div>
    </dl>

    <p class="help">{{ __('installer.done.secrets') }}</p>

    <h2>{{ __('installer.done.cron.heading') }}</h2>
    <div class="notice warn">
        <p>{{ __('installer.done.cron.intro') }}</p>
        <pre><code>* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run
* * * * * cd {{ base_path() }} &amp;&amp; php artisan queue:work --stop-when-empty --max-time=55 --tries=3</code></pre>
    </div>

    @if (! empty($summary['warnings']))
        <h2>{{ __('installer.done.warnings') }}</h2>
        <ul class="checks">
            @foreach ($summary['warnings'] as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('installer.done.integrations.heading') }}</h2>
    <p class="lead">{{ __('installer.done.integrations.intro') }}</p>
    <ul class="checks">
        @foreach (['turnstile', 'sentry', 'analytics', 's3'] as $integration)
            <li>{{ __('installer.done.integrations.items.'.$integration) }}</li>
        @endforeach
    </ul>

    <div class="actions">
        <a class="button" href="{{ $summary['app_url'] ?? url('/') }}">{{ __('installer.actions.open_site') }}</a>
        <a class="button secondary" href="{{ rtrim($summary['app_url'] ?? url('/'), '/') }}/admin">{{ __('installer.actions.open_admin') }}</a>
    </div>
@endsection
