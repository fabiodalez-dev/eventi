<li>
    <div class="row">
        <span class="name">{{ $requirement->label() }}</span>
        <span class="tag {{ $requirement->status->value }}">{{ $requirement->status->label() }}</span>
    </div>
    @if ($requirement->hint())
        <span class="help">{{ $requirement->hint() }}</span>
    @endif
    @if ($requirement->command && $requirement->status !== \App\Enums\RequirementStatus::Ok)
        <pre><code>{{ $requirement->command }}</code></pre>
    @endif
</li>
