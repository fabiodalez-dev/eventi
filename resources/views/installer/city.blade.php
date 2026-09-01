@extends('installer.layout')

@section('content')
    <form method="POST" action="{{ $step->url() }}">
        @csrf

        @include('installer.partials.field', [
            'name' => 'name',
            'label' => __('installer.city.labels.name'),
            'value' => $values['name'] ?? '',
        ])

        @include('installer.partials.field', [
            'name' => 'slug',
            'label' => __('installer.city.labels.slug'),
            'value' => $values['slug'] ?? '',
            'required' => false,
            'help' => __('installer.city.help.slug'),
            'attributes' => ['autocapitalize' => 'off', 'spellcheck' => 'false'],
        ])

        <div class="pair">
            @include('installer.partials.field', [
                'name' => 'province_code',
                'label' => __('installer.city.labels.province_code'),
                'value' => $values['province_code'] ?? '',
                'help' => __('installer.city.help.province_code'),
                'attributes' => ['maxlength' => '2', 'autocapitalize' => 'characters'],
            ])

            @include('installer.partials.field', [
                'name' => 'province_name',
                'label' => __('installer.city.labels.province_name'),
                'value' => $values['province_name'] ?? '',
            ])
        </div>

        @include('installer.partials.field', [
            'name' => 'region',
            'label' => __('installer.city.labels.region'),
            'value' => $values['region'] ?? '',
        ])

        <div class="field">
            <label for="timezone">{{ __('installer.city.labels.timezone') }}</label>
            <select id="timezone" name="timezone" required>
                @foreach ($timezones as $timezone)
                    <option value="{{ $timezone }}" @selected(old('timezone', $values['timezone'] ?? 'Europe/Rome') === $timezone)>{{ $timezone }}</option>
                @endforeach
            </select>
            <span class="help">{{ __('installer.city.help.timezone') }}</span>
            @error('timezone')<span class="error">{{ $message }}</span>@enderror
        </div>

        <p class="help">{{ __('installer.city.help.coordinates') }}</p>
        <p>
            {{-- Il collegamento parte solo se lo apre chi installa: nessun
                 trasferimento di dati verso terzi all'apertura della pagina. --}}
            <a href="https://www.openstreetmap.org/" target="_blank" rel="noopener noreferrer">{{ __('installer.city.osm.link') }}</a>
        </p>
        <p class="help">{{ __('installer.city.osm.note') }}</p>

        <div class="pair">
            @include('installer.partials.field', [
                'name' => 'center_lat',
                'label' => __('installer.city.labels.center_lat'),
                'value' => $values['center_lat'] ?? '',
                'attributes' => ['inputmode' => 'decimal', 'placeholder' => '45.4064'],
            ])

            @include('installer.partials.field', [
                'name' => 'center_lng',
                'label' => __('installer.city.labels.center_lng'),
                'value' => $values['center_lng'] ?? '',
                'attributes' => ['inputmode' => 'decimal', 'placeholder' => '11.8768'],
            ])
        </div>

        @include('installer.partials.field', [
            'name' => 'radius_km',
            'label' => __('installer.city.labels.radius_km'),
            'value' => $values['radius_km'] ?? '30',
            'help' => __('installer.city.help.radius_km'),
            'attributes' => ['inputmode' => 'numeric'],
        ])

        <div class="actions">
            <button type="submit">{{ __('installer.actions.continue') }}</button>
        </div>
    </form>
@endsection
