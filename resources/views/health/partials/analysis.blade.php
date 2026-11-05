@php
    // The AI's own reading of the metrics, when it produced one. Lives inside this
    // partial so the gauge + bars render identically with or without prose.
    $analysis = $briefing['data_analysis'] ?? [];
    $bandKey = ['self_care' => 'selfcare', 'pharmacist_review' => 'pharmacist', 'refer_doctor' => 'refer', 'emergency' => 'emergency'][$session->outcome] ?? 'pharmacist';

    // Ring geometry: r=52, circumference = 2πr. The arc sweeps the score fraction.
    $radius = 52;
    $circumference = round(2 * M_PI * $radius, 2);
    $offset = round($circumference * (1 - min(100, max(0, $metrics['score'])) / 100), 2);

    // One legend chip per band, in scale order. The active one carries the case.
    $bands = [
        ['key' => 'selfcare', 'label' => __('messages.health.outcome_self_care'), 'range' => '0–24'],
        ['key' => 'pharmacist', 'label' => __('messages.health.outcome_pharmacist_review'), 'range' => '25–49'],
        ['key' => 'refer', 'label' => __('messages.health.outcome_refer_doctor'), 'range' => '50–74'],
        ['key' => 'emergency', 'label' => __('messages.health.outcome_emergency'), 'range' => '75–100'],
    ];

    // Count the recorded facts the score read, for the source line.
    $factCount = count(array_filter((array) $session->matched_symptoms))
        + count((array) $session->triggered_red_flags)
        + ($session->answerFor('duration_days') !== null ? 1 : 0)
        + (data_get($session->ai_meta, 'age') !== null ? 1 : 0)
        + (int) $session->ai_clarify_rounds;
@endphp

{{-- A rule-based risk score, not a probability: the outcome's band anchors it, so
     the number can never contradict the conclusion banner. Contributions are the
     weights the factors actually carried — numbers that exist nowhere else on the
     page. Computed from persisted columns; renders with or without AI. --}}
<div class="health-analysis" id="briefing-analysis">
    <div class="health-analysis__head">
        <h3 class="health-brief__section-title mb-0">
            <i class="bi bi-activity" aria-hidden="true"></i>{{ __('messages.health.analysis_title') }}
        </h3>
        <p class="health-analysis__source small mb-0">
            {{ __('messages.health.analysis_source', ['count' => $factCount]) }}
        </p>
    </div>

    <div class="health-gauge-row" role="img"
         aria-label="{{ __('messages.health.analysis_score_aria', ['score' => $metrics['score'], 'level' => $metrics['level_label']]) }}">
        <div class="health-gauge">
            <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="health-gauge__bg" cx="60" cy="60" r="{{ $radius }}"/>
                <circle class="health-gauge__arc health-gauge__arc--{{ $bandKey }}" cx="60" cy="60" r="{{ $radius }}"
                        stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}"/>
            </svg>
            <div class="health-gauge__center">
                <span class="health-gauge__value">{{ $metrics['score'] }}</span>
                <span class="health-gauge__max">/100</span>
            </div>
        </div>
        <div class="health-gauge__side">
            <p class="health-gauge__level mb-1">{{ __('messages.health.analysis_score_level', ['level' => $metrics['level_label']]) }}</p>
            <p class="small mb-0" style="color: var(--health-ink-soft);">
                {{ __('messages.health.analysis_band_note', ['from' => $metrics['band_base'], 'to' => $metrics['band_top']]) }}
            </p>
        </div>
    </div>

    {{-- Band legend, reference-style chips. The active band is filled; the rest stay
         quiet so the eye lands on where this case sits. --}}
    <div class="health-bands" aria-hidden="true">
        @foreach ($bands as $band)
            <span class="health-band health-band--{{ $band['key'] }}{{ $band['key'] === $bandKey ? ' is-active' : '' }}">
                @if ($band['key'] === $bandKey)<i class="bi bi-geo-alt-fill" aria-hidden="true"></i>@endif
                {{ $band['label'] }}
                <span class="health-band__range">{{ $band['range'] }}</span>
            </span>
        @endforeach
    </div>

    {{-- Bars show what each factor contributed. Only non-zero factors get a bar;
         the zero ones collapse into one line so the chart never shows empty rows. --}}
    @if ($metrics['contributions'])
        <div class="health-contribution">
            <p class="small fw-semibold text-gray-700 mb-2">{{ __('messages.health.analysis_contribution_title') }}</p>
            @foreach ($metrics['contributions'] as $bar)
                <div class="health-contribution__row{{ $bar['points'] === 0 ? ' is-zero' : '' }}">
                    <span class="health-contribution__label">{{ $bar['label'] }}</span>
                    <span class="health-contribution__track" aria-hidden="true">
                        <span class="health-contribution__fill" style="width: {{ $bar['width'] }}%;"></span>
                    </span>
                    <span class="health-contribution__points">+{{ $bar['points'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($analysis !== [])
        <div class="health-analysis__reading">
            <p class="small fw-semibold text-gray-700 mb-2">
                <i class="bi bi-robot me-1" aria-hidden="true"></i>{{ __('messages.health.analysis_reading_label') }}
            </p>
            <ul class="health-brief__list mb-0">
                @foreach ($analysis as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="health-analysis__note small mb-0">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.analysis_note') }}
    </p>
</div>
