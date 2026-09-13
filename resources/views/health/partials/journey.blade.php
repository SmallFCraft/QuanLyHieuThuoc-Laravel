@php
    // Same five rows the collapsed "what you told us" details renders, promoted to
    // the page's spine: the visitor asked a question and the answers that produced
    // it belong in view, not behind a chevron. `skipped` is a real answer here too —
    // "bỏ qua" is information, and hiding it would imply the rule engine had more
    // to go on than it did.
    $data = $session->collected_data ?? [];
    $skipped = (array) ($data['skipped'] ?? []);

    $stepValue = static function (string $key, $value) use ($skipped): ?string {
        if ($value === null || $value === '' || $value === []) {
            return in_array($key, $skipped, true) ? __('messages.health.trail_skipped') : null;
        }

        return is_array($value) ? implode(', ', $value) : (string) $value;
    };

    $steps = [
        ['key' => 'raw_symptom_text', 'label' => __('messages.health.step_1'), 'value' => $data['raw_symptom_text'] ?? ($data['symptoms'] ?? null)],
        ['key' => 'duration_days', 'label' => __('messages.health.step_2'), 'value' => $data['duration_days'] ?? null],
        ['key' => 'medications_taken', 'label' => __('messages.health.step_3'), 'value' => $data['medications_taken'] ?? null],
        ['key' => 'history', 'label' => __('messages.health.step_4'), 'value' => $data['history'] ?? null],
        ['key' => 'other_symptoms', 'label' => __('messages.health.step_5'), 'value' => $data['other_symptoms'] ?? null],
    ];
@endphp

<section class="health-card rounded-4 border-0 p-4 mt-4" aria-labelledby="journey-heading">
    <h2 class="h6 fw-semibold text-gray-800 mb-3" id="journey-heading">
        <i class="bi bi-signpost-split me-1" aria-hidden="true"></i>{{ __('messages.health.journey_title') }}
    </h2>

    <ol class="health-journey" aria-label="{{ __('messages.health.journey_aria') }}">
        @foreach ($steps as $index => $step)
            @php $value = $stepValue($step['key'], $step['value']); @endphp
            {{-- A step with no answer is dropped from the rail rather than rendered
                 empty; the outcome node is always last and always present. --}}
            @continue($value === null)
            <li class="health-journey__step" style="--journey-index: {{ $index }};">
                <span class="health-journey__dot" aria-hidden="true"></span>
                <span class="health-journey__label">{{ $step['label'] }}</span>
                <span class="health-journey__value">{{ $value }}</span>
            </li>
        @endforeach

        <li class="health-journey__step health-journey__step--outcome" style="--journey-index: {{ count($steps) }};">
            <span class="health-journey__dot" aria-hidden="true"></span>
            <span class="health-journey__label">{{ __('messages.health.journey_outcome') }}</span>
            <span class="health-journey__value health-outcome__title--{{ $outcome ?? 'self_care' }}">
                {{ $outcomeLabel ?? '' }}
            </span>
        </li>
    </ol>
</section>
