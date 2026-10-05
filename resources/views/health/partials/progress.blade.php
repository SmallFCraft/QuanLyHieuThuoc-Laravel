@php
    $current = (int) ($question['step'] ?? 1);
    $total = (int) ($question['total'] ?? 5);
    $percent = (int) round((($current - 1) / $total) * 100);

    // 5 step names aligned with MedicalTriageService questions & lang/vi/health.php
    $stepNames = [
        1 => __('messages.health.step_1'),
        2 => __('messages.health.step_2'),
        3 => __('messages.health.step_3'),
        4 => __('messages.health.step_4'),
        5 => __('messages.health.step_5'),
    ];
@endphp

{{-- Visual 5-step clinical progress stepper.
     Retains role=progressbar contract, aria-valuetext, and the .health-progress-track/fill
     classes so all existing tests pass while providing real clinical visual feedback. --}}
<div class="health-stepper mb-4">
    {{-- Accessible text for screen readers & tests --}}
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
        <span class="health-stepper__badge">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
            {{ __('messages.health.progress_label', ['current' => $current, 'total' => $total]) }}
        </span>
        <span class="small fw-medium" style="color: var(--health-ink-mute);">
            {{ $question['title'] ?? ($stepNames[$current] ?? '') }}
        </span>
    </div>

    {{-- The contract progress track (visually refined, pins all aria requirements) --}}
    <div class="health-progress-track"
         role="progressbar"
         aria-label="{{ __('messages.health.progress_aria') }}"
         aria-valuemin="0"
         aria-valuemax="{{ $total }}"
         aria-valuenow="{{ $current - 1 }}"
         aria-valuetext="{{ __('messages.health.progress_label', ['current' => $current, 'total' => $total]) }}">
        <div class="health-progress-fill" style="width: {{ $percent }}%"></div>
    </div>

    {{-- 5 Step Indicator Nodes: instant visual sense of where the customer is --}}
    <ol class="health-stepper__nodes health-no-print" aria-hidden="true">
        @for ($i = 1; $i <= $total; $i++)
            @php
                $isDone = $i < $current;
                $isCurrent = $i === $current;
                $stateClass = $isDone ? 'is-done' : ($isCurrent ? 'is-current' : 'is-pending');
            @endphp
            <li class="health-stepper__node {{ $stateClass }}">
                <span class="health-stepper__dot">
                    @if ($isDone)
                        <i class="bi bi-check-lg"></i>
                    @else
                        {{ $i }}
                    @endif
                </span>
                <span class="health-stepper__node-label">{{ $stepNames[$i] ?? "Bước $i" }}</span>
            </li>
        @endfor
    </ol>
</div>
