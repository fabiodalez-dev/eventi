@extends('installer.layout')

@section('content')
    <ul class="checks">
        @foreach ($tasks as $task)
            @php($done = $state->isDone($task))
            <li>
                <div class="row">
                    <span class="name">{{ $task->label() }}</span>
                    <span class="tag {{ $done ? 'ok' : ($task === $next ? 'avviso' : '') }}">
                        {{ $done ? __('installer.run.done') : ($task === $next ? __('installer.run.current') : __('installer.run.pending')) }}
                    </span>
                </div>
                <span class="help">{{ $task->description() }}</span>
            </li>
        @endforeach
    </ul>

    <p class="help">{{ __('installer.run.note') }}</p>

    <form method="POST" action="{{ $step->url() }}">
        @csrf
        <div class="actions">
            <button type="submit">
                {{ $next === null ? __('installer.actions.finish') : __('installer.actions.run') }}
            </button>
        </div>
    </form>
@endsection
