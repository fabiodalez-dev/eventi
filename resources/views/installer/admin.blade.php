@extends('installer.layout')

@section('content')
    <form method="POST" action="{{ $step->url() }}">
        @csrf

        @include('installer.partials.field', [
            'name' => 'name',
            'label' => __('installer.admin.labels.name'),
            'value' => $values['name'] ?? '',
            'attributes' => ['autocomplete' => 'name'],
        ])

        @include('installer.partials.field', [
            'name' => 'email',
            'label' => __('installer.admin.labels.email'),
            'type' => 'email',
            'value' => $values['email'] ?? '',
            'help' => __('installer.admin.help.email'),
            'attributes' => ['autocomplete' => 'username'],
        ])

        @include('installer.partials.field', [
            'name' => 'password',
            'label' => __('installer.admin.labels.password'),
            'type' => 'password',
            'help' => __('installer.admin.help.password'),
            'attributes' => ['autocomplete' => 'new-password'],
        ])

        @include('installer.partials.field', [
            'name' => 'password_confirmation',
            'label' => __('installer.admin.labels.password_confirmation'),
            'type' => 'password',
            'attributes' => ['autocomplete' => 'new-password'],
        ])

        <div class="actions">
            <button type="submit">{{ __('installer.actions.continue') }}</button>
        </div>
    </form>
@endsection
