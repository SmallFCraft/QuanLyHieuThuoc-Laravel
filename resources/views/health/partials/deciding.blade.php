{{-- Waiting state for a queued decision.
     Not a spinner: the provider call takes 11-30s, so this screen says what is
     happening and shows the answers back while it works. Progressive enhancement —
     with JavaScript off the reload link does the same job. --}}
<div class="health-card p-4 p-md-5"
     data-triage-deciding
     data-poll-url="{{ route('health.decision', $pendingJobId) }}"
     data-slow-notice="{{ __('messages.health.deciding_slow') }}">

    <div class="d-flex gap-3 align-items-start mb-4">
        <span class="health-icon-tile" aria-hidden="true">
            <i class="bi bi-activity health-deciding__glyph"></i>
        </span>
        <div>
            <h2 class="mb-1" id="triage-question" tabindex="-1">
                {{ __('messages.health.deciding_title') }}
            </h2>
            <p class="small mb-0" style="color: var(--health-ink-soft);">{{ __('messages.health.deciding_body') }}</p>
        </div>
    </div>

    <ul class="health-deciding-steps mb-4">
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ __('messages.health.deciding_step_reading') }}</li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ __('messages.health.deciding_step_rules') }}</li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ __('messages.health.deciding_step_flags') }}</li>
    </ul>

    {{-- The live region is the text, not the animated icon: under reduced motion the
         animation is frozen, so the words have to carry the state. --}}
    <p class="small mb-3" role="status" data-triage-deciding-status style="color: var(--health-ink-mute);">
        {{ __('messages.health.deciding_body') }}
    </p>

    @include('health.partials.trail', ['answeredSteps' => $answeredSteps])

    <a href="{{ route('health.check') }}" class="health-btn health-btn-ghost">
        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>{{ __('messages.health.deciding_retry') }}
    </a>
</div>
