@php
    $current = $question['step'] ?? 1;
    $total = $question['total'] ?? 5;
    $percent = (int) round((($current - 1) / $total) * 100);
@endphp

{{-- Progress is exposed to assistive tech via role=progressbar AND rendered as
     "Bước n/5" text, so it is never colour/position only.

     The numbered dot strip is gone: it duplicated the bar and the text, and the
     answers themselves (partials/trail) are a truer record of where the customer is.
     TOTAL_STEPS and the aria contract are unchanged. --}}
<div class="mb-4">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
        <span class="small fw-semibold" style="color: var(--health-selfcare);">
            {{ __('messages.health.progress_label', ['current' => $current, 'total' => $total]) }}
        </span>
        <span class="small" style="color: var(--health-ink-mute);">{{ $question['title'] ?? '' }}</span>
    </div>

    <div class="health-progress-track"
         role="progressbar"
         aria-label="{{ __('messages.health.progress_aria') }}"
         aria-valuemin="0"
         aria-valuemax="{{ $total }}"
         aria-valuenow="{{ $current - 1 }}"
         aria-valuetext="{{ __('messages.health.progress_label', ['current' => $current, 'total' => $total]) }}">
        <div class="health-progress-fill" style="width: {{ $percent }}%"></div>
    </div>
</div>
