@php
    // Same five rows the collapsed "what you told us" details renders, promoted to the
    // page's spine: the visitor asked a question and the answers that produced it belong
    // in view, not behind a chevron. `skipped` is a real answer here too — "bỏ qua" is
    // information, and hiding it would imply the rule engine had more to go on than it
    // did.
    $data = $session->collected_data ?? [];
    $skipped = (array) ($data['skipped'] ?? []);

    $stepValue = static function (string $key, $value) use ($skipped): ?string {
        if ($value === null || $value === '' || $value === []) {
            return in_array($key, $skipped, true) ? __('messages.health.trail_skipped') : null;
        }

        // Cut on the character, not the word: this is a label on a rail, not prose, and
        // the full text is still in the audit tab. A hard cut reads as a deliberate
        // truncation; stopping at a word boundary here reads as a rendering bug.
        $text = is_array($value) ? implode(', ', $value) : trim((string) $value);

        return mb_strlen($text) > 30 ? mb_substr($text, 0, 30) . '…' : $text;
    };

    // Days arrive as a bare number. "1" alone reads as a count of nothing, so the unit
    // travels with the value. Guarded on `is_numeric`, not on emptiness: 0 is a real
    // answer (symptoms started today) and must not be read as "no answer" — the same
    // rule the service's own isStepAnswered uses, so rail and engine agree.
    $duration = is_numeric($data['duration_days'] ?? null)
        ? ((int) $data['duration_days'] <= 1
            ? __('messages.health.basis_duration_today')
            : __('messages.health.basis_duration_days', ['days' => (int) $data['duration_days']]))
        : null;

    $candidates = [
        // Step 1's skip marker is `symptoms`; `raw_symptom_text` is the readable copy of
        // the same answer, which is what the rail shows. Two keys, one step.
        ['key' => 'symptoms', 'label' => __('messages.health.step_1'), 'value' => $data['raw_symptom_text'] ?? ($data['symptoms'] ?? null)],
        ['key' => 'duration_days', 'label' => __('messages.health.step_2'), 'value' => $duration],
        ['key' => 'medications_taken', 'label' => __('messages.health.step_3'), 'value' => $data['medications_taken'] ?? null],
        ['key' => 'history', 'label' => __('messages.health.step_4'), 'value' => $data['history'] ?? null],
        ['key' => 'other_symptoms', 'label' => __('messages.health.step_5'), 'value' => $data['other_symptoms'] ?? null],
    ];

    // Filtered here rather than with @continue in the loop: the position in the loop is
    // the step's number on the rail, and a dropped step must not leave a hole in the
    // count. "1, 2, 4" reads as a missing answer, which is a different claim from "you
    // skipped this one".
    $steps = [];
    foreach ($candidates as $candidate) {
        $value = $stepValue($candidate['key'], $candidate['value']);

        if ($value !== null) {
            $steps[] = ['label' => $candidate['label'], 'value' => $value];
        }
    }

    // The rail closes on the conclusion, so it needs the outcome whether or not the
    // caller passed one. Same default the page uses for the verdict card.
    $outcome = $outcome ?? 'self_care';
    $outcomeLabel = $outcomeLabel ?? '';
@endphp

{{-- A sticky sidebar, not a card in the column.

     The rail is a path, so it wants to be beside the thing it explains. Down the left
     it also stops competing with the tab hub for the reader's attention: the hub is
     what they scroll, the rail is where they are in it. `.health-shell--rail` widens
     the page to make room, and below 992px there is no room, so the whole element
     leaves (see `.health-rail` in the stylesheet) rather than reflowing into a second
     long block at the top — the audit tab already carries the same answers in full. --}}
<aside class="health-rail" aria-labelledby="journey-heading">
    {{-- Red flags stay in the pinned rail when not emergency (e.g. refer_doctor
         cases that carry a caution). The 115 call action itself moved to the top of
         the main content (result.blade.php): the rail is display:none on mobile, and an
         emergency call button that disappears on a phone is an emergency defect.
         Red flags on an emergency page are also listed there; the rail keeps them
         when emergency is false but red flags were triggered. --}}
    @if (!$session->isEmergency() && !empty($session->triggered_red_flags))
        <div class="health-rail__flags">
            <h2 class="h6 fw-semibold text-gray-800 mb-2">{{ __('messages.health.red_flags_title') }}</h2>
            <ul class="list-unstyled mb-0 d-flex flex-wrap gap-2">
                @foreach ($session->triggered_red_flags as $flag)
                    {{-- white-space: normal — a long red-flag name has no headroom in
                         a pill inside the sidebar's 264px track. --}}
                    <li class="badge bg-white text-gray-800 border px-3 py-2 rounded-pill fw-normal"
                        style="white-space: normal; text-align: start;">
                        <i class="bi bi-dot" aria-hidden="true"></i>{{ $flag['name_vi'] ?? '' }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- The same head idiom the care and products blocks use — icon tile, title,
         explanation. It was dropped when the rail moved into the sidebar, which left it
         as a bare list of labels next to two blocks that both announce themselves; the
         rail is the one that most needs the explanation line, since a numbered list of
         fragments does not say what it is the journey of. --}}
    <div class="health-rail__head">
        <span class="health-icon-tile" aria-hidden="true">
            <i class="bi bi-signpost-split"></i>
        </span>
        <div>
            <h2 class="health-rail__title" id="journey-heading">{{ __('messages.health.journey_title') }}</h2>
            <p class="health-rail__hint">{{ __('messages.health.journey_aria') }}</p>
        </div>
    </div>

    {{-- role="list" because `list-style: none` drops list semantics in Safari and
         VoiceOver, and this list is ordinal content: the numbers are the point. --}}
    <ol class="health-journey" role="list">
        @foreach ($steps as $index => $step)
            <li class="health-journey__step" style="--journey-index: {{ $index }};">
                <span class="health-journey__dot" aria-hidden="true">{{ $index + 1 }}</span>
                <span class="health-journey__label">{{ $step['label'] }}</span>
                <span class="health-journey__value">{{ $step['value'] }}</span>
            </li>
        @endforeach

        <li class="health-journey__step health-journey__step--outcome" style="--journey-index: {{ count($steps) }};">
            <span class="health-journey__dot health-journey__dot--{{ $outcome }}" aria-hidden="true">
                <i class="bi {{ $outcomeIcons[$outcome] ?? 'bi-shield-check' }}"></i>
            </span>
            <span class="health-journey__label">{{ __('messages.health.journey_outcome') }}</span>
            <span class="health-journey__value health-outcome__title--{{ $outcome }}">
                {{ $outcomeLabel }}
            </span>
        </li>
    </ol>
</aside>