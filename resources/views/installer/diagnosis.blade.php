@extends('installer.layout')

@section('content')
    <p>{{ __('installer.diagnosis.intro') }}</p>

    <ul class="checks">
        @foreach ($problems as $problem)
            <li>
                <div class="row">
                    <span class="name">{{ __('installer.diagnosis.problems.'.$problem['key'], ['tables' => $problem['tables'] ?? '']) }}</span>
                    <span class="tag bloccante">{{ __('installer.requirements.status.bloccante') }}</span>
                </div>
            </li>
        @endforeach
    </ul>

    <h2>{{ __('installer.diagnosis.commands') }}</h2>
    <pre><code>php artisan migrate --force
php artisan config:clear
php artisan optimize</code></pre>

    <p class="help">{{ __('installer.diagnosis.lock', ['path' => $lock]) }}</p>

    <div class="actions">
        <a class="button" href="{{ url()->current() }}">{{ __('installer.actions.retry') }}</a>
    </div>
@endsection
