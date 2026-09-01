@extends('installer.layout')

@section('content')
    <form method="POST" action="{{ $step->url() }}">
        @csrf

        @include('installer.partials.field', [
            'name' => 'db_host',
            'label' => __('installer.database.labels.db_host'),
            'value' => $values['db_host'] ?? '127.0.0.1',
            'help' => __('installer.database.help.db_host'),
        ])

        @include('installer.partials.field', [
            'name' => 'db_port',
            'label' => __('installer.database.labels.db_port'),
            'value' => $values['db_port'] ?? '3306',
            'help' => __('installer.database.help.db_port'),
            'attributes' => ['inputmode' => 'numeric'],
        ])

        @include('installer.partials.field', [
            'name' => 'db_database',
            'label' => __('installer.database.labels.db_database'),
            'value' => $values['db_database'] ?? '',
            'help' => __('installer.database.help.db_database'),
            'attributes' => ['autocapitalize' => 'off', 'autocomplete' => 'off', 'spellcheck' => 'false'],
        ])

        @include('installer.partials.field', [
            'name' => 'db_username',
            'label' => __('installer.database.labels.db_username'),
            'value' => $values['db_username'] ?? '',
            'attributes' => ['autocapitalize' => 'off', 'autocomplete' => 'off', 'spellcheck' => 'false'],
        ])

        @include('installer.partials.field', [
            'name' => 'db_password',
            'label' => __('installer.database.labels.db_password'),
            'type' => 'password',
            'required' => false,
            'help' => __('installer.database.help.db_password'),
            'attributes' => ['autocomplete' => 'new-password'],
        ])

        <div class="actions">
            <button type="submit">{{ __('installer.actions.continue') }}</button>
        </div>
    </form>
@endsection
