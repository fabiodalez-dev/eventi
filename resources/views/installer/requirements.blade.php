@extends('installer.layout')

@section('content')
    <h2>{{ __('installer.requirements.blocking_heading') }}</h2>
    <ul class="checks">
        @foreach ($mandatory as $requirement)
            @include('installer.partials.requirement', ['requirement' => $requirement])
        @endforeach
    </ul>

    <h2>{{ __('installer.requirements.advisory_heading') }}</h2>
    <p class="lead">{{ __('installer.requirements.advisory_intro') }}</p>
    <ul class="checks">
        @foreach ($advisory as $requirement)
            @include('installer.partials.requirement', ['requirement' => $requirement])
        @endforeach
    </ul>

    <form method="POST" action="{{ $step->url() }}">
        @csrf
        <div class="actions">
            <button type="submit">{{ $passes ? __('installer.actions.continue') : __('installer.actions.recheck') }}</button>
        </div>
    </form>
@endsection
