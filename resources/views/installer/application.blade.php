@extends('installer.layout')

@section('content')
    <form method="POST" action="{{ $step->url() }}">
        @csrf

        @include('installer.partials.field', [
            'name' => 'app_name',
            'label' => __('installer.application.labels.app_name'),
            'value' => $values['app_name'] ?? __('installer.app'),
            'help' => __('installer.application.help.app_name'),
        ])

        @include('installer.partials.field', [
            'name' => 'app_url',
            'label' => __('installer.application.labels.app_url'),
            'type' => 'url',
            'value' => $values['app_url'] ?? '',
            'help' => __('installer.application.help.app_url'),
        ])

        <div class="field">
            <label for="mail_mailer">{{ __('installer.application.labels.mail_mailer') }}</label>
            <select id="mail_mailer" name="mail_mailer" required>
                @foreach (['log', 'smtp'] as $mailer)
                    <option value="{{ $mailer }}" @selected(old('mail_mailer', $values['mail_mailer'] ?? 'log') === $mailer)>
                        {{ __('installer.application.mailers.'.$mailer) }}
                    </option>
                @endforeach
            </select>
            <span class="help">{{ __('installer.application.help.mail_mailer') }}</span>
            @error('mail_mailer')<span class="error">{{ $message }}</span>@enderror
        </div>

        <div class="pair">
            @include('installer.partials.field', [
                'name' => 'mail_host',
                'label' => __('installer.application.labels.mail_host'),
                'value' => $values['mail_host'] ?? '',
                'required' => false,
            ])

            @include('installer.partials.field', [
                'name' => 'mail_port',
                'label' => __('installer.application.labels.mail_port'),
                'value' => $values['mail_port'] ?? '587',
                'required' => false,
                'attributes' => ['inputmode' => 'numeric'],
            ])
        </div>

        <div class="pair">
            @include('installer.partials.field', [
                'name' => 'mail_username',
                'label' => __('installer.application.labels.mail_username'),
                'value' => $values['mail_username'] ?? '',
                'required' => false,
                'attributes' => ['autocomplete' => 'off'],
            ])

            @include('installer.partials.field', [
                'name' => 'mail_password',
                'label' => __('installer.application.labels.mail_password'),
                'type' => 'password',
                'required' => false,
                'attributes' => ['autocomplete' => 'new-password'],
            ])
        </div>

        <div class="field">
            <label for="mail_scheme">{{ __('installer.application.labels.mail_scheme') }}</label>
            <select id="mail_scheme" name="mail_scheme">
                @foreach (['auto', 'smtps'] as $scheme)
                    <option value="{{ $scheme }}" @selected(old('mail_scheme', $values['mail_scheme'] ?? 'auto') === $scheme)>
                        {{ __('installer.application.schemes.'.$scheme) }}
                    </option>
                @endforeach
            </select>
            <span class="help">{{ __('installer.application.help.mail_scheme') }}</span>
        </div>

        @include('installer.partials.field', [
            'name' => 'mail_from_address',
            'label' => __('installer.application.labels.mail_from_address'),
            'type' => 'email',
            'value' => $values['mail_from_address'] ?? '',
            'required' => false,
            'help' => __('installer.application.help.mail_from_address'),
        ])

        @include('installer.partials.field', [
            'name' => 'ops_alert_email',
            'label' => __('installer.application.labels.ops_alert_email'),
            'type' => 'email',
            'value' => $values['ops_alert_email'] ?? '',
            'required' => false,
            'help' => __('installer.application.help.ops_alert_email'),
        ])

        <p class="help">{{ __('installer.application.production_note') }}</p>

        <div class="actions">
            <button type="submit">{{ __('installer.actions.continue') }}</button>
        </div>
    </form>
@endsection
