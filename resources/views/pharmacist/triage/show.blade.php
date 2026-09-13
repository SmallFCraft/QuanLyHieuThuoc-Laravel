@extends('pharmacist.layout')

@section('title', __('messages.pharmacist.detail_title', ['id' => $session->MaSL]))

@section('content')
@php
    // `tone` names the --health-* variable family in health/partials/styles.blade.php,
    // so the banner reuses the severity scale instead of inventing its own palette.
    $outcomeMeta = [
        'emergency' => ['icon' => 'bi-exclamation-octagon-fill', 'label' => __('messages.health.outcome_emergency'), 'severity' => 'emergency', 'tone' => 'emergency'],
        'refer_doctor' => ['icon' => 'bi-hospital-fill', 'label' => __('messages.health.outcome_refer_doctor'), 'severity' => 'urgent', 'tone' => 'refer'],
        'pharmacist_review' => ['icon' => 'bi-person-badge-fill', 'label' => __('messages.health.outcome_pharmacist_review'), 'severity' => 'caution', 'tone' => 'pharmacist'],
        'self_care' => ['icon' => 'bi-house-heart-fill', 'label' => __('messages.health.outcome_self_care'), 'severity' => 'calm', 'tone' => 'selfcare'],
    ];
    $outcomeKey = isset($outcomeMeta[$session->outcome]) ? $session->outcome : 'pharmacist_review';
    $outcome = $outcomeMeta[$outcomeKey];
    $flagIcons = [
        'emergency' => 'bi-exclamation-octagon-fill',
        'urgent' => 'bi-hospital-fill',
        'caution' => 'bi-person-badge-fill',
    ];
    $allergies = array_filter((array) ($profile->allergies ?? []));
    $chronic = array_filter((array) ($profile->chronic_conditions ?? []));
    $medications = array_filter((array) ($profile->current_medications ?? []));
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-gray-900 mb-1">{{ __('messages.pharmacist.detail_title', ['id' => $session->MaSL]) }}</h1>
        <p class="text-gray-600 mb-0">{{ __('messages.pharmacist.detail_subtitle') }}</p>
    </div>
    <a href="{{ route('pharmacist.triage.index') }}" class="rx-btn rx-btn-ghost">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>{{ __('messages.pharmacist.btn_back_queue') }}
    </a>
</div>

{{-- Decision banner. Outcome, red flags and matched symptoms come before every other
     fact on the page: a pharmacist has to judge severity in about two seconds, and
     everything below this block is supporting detail. --}}
<section class="rx-card health-outcome health-outcome--{{ $outcomeKey }} p-4 mb-4"
         style="background: var(--health-{{ $outcome['tone'] }}-bg); border-color: var(--health-{{ $outcome['tone'] }}-border);"
         aria-labelledby="rx-decision-title">
    <p class="rx-metric-label mb-1">{{ __('messages.pharmacist.system_outcome_title') }}</p>
    {{-- Colour plus icon plus the outcome text: severity never rests on hue alone. --}}
    <h2 class="h4 fw-bold mb-3 d-flex flex-wrap align-items-center gap-2"
        id="rx-decision-title"
        style="color: var(--health-{{ $outcome['tone'] }});">
        <i class="bi {{ $outcome['icon'] }}" aria-hidden="true"></i>{{ $outcome['label'] }}
    </h2>

    @if (!empty($session->triggered_red_flags))
        <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.red_flags_title') }}</h3>
        <ul class="list-unstyled d-flex flex-wrap gap-2 mb-3">
            @foreach ($session->triggered_red_flags as $flag)
                @php $severity = $flag['severity'] ?? 'caution'; @endphp
                <li class="rx-badge rx-badge--{{ $severity }}">
                    <i class="bi {{ $flagIcons[$severity] ?? 'bi-person-badge-fill' }}" aria-hidden="true"></i>
                    {{ $flag['name_vi'] ?? ($flag['code'] ?? '') }}
                    <span class="fw-normal">· {{ __('messages.pharmacist.severity_' . $severity) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if (!empty($symptomNames))
        <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.matched_symptoms_title') }}</h3>
        <p class="text-gray-900 mb-0">{{ implode(', ', $symptomNames) }}</p>
    @endif
</section>

@error('pharmacist_note')
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
        <div>{{ $message }}</div>
    </div>
@enderror

<div class="row g-4">
    {{-- Clinical facts, one column: nothing the pharmacist must weigh is split across
         two panels of equal weight. --}}
    <div class="col-12 col-lg-7">
        <section class="rx-card p-4 mb-4" aria-labelledby="rx-customer-title">
            <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-customer-title">
                <i class="bi bi-person-vcard me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.customer_title') }}
            </h2>
            <dl class="rx-dl row mb-0">
                <dt class="col-sm-5">{{ __('messages.pharmacist.customer_title') }}</dt>
                <dd class="col-sm-7">{{ $session->user?->name ?? __('messages.pharmacist.customer_unknown') }}</dd>

                <dt class="col-sm-5">{{ __('messages.pharmacist.col_age') }}</dt>
                <dd class="col-sm-7">{{ $age !== null ? __('messages.pharmacist.age_years', ['age' => $age]) : __('messages.pharmacist.age_unknown') }}</dd>

                <dt class="col-sm-5">{{ __('messages.pharmacist.created_at_label') }}</dt>
                <dd class="col-sm-7">
                    <time datetime="{{ $session->created_at->toIso8601String() }}">{{ $session->created_at->format('d/m/Y H:i') }}</time>
                </dd>

                <dt class="col-sm-5">{{ __('messages.pharmacist.waiting_label') }}</dt>
                <dd class="col-sm-7 mb-0">{{ $session->created_at->locale('vi')->diffForHumans(['parts' => 1]) }}</dd>
            </dl>
        </section>

        {{-- Health profile. Allergies are the loudest item on the page: missing one is
             the worst failure this screen can cause, so each gets its own emergency
             pill instead of one more comma-joined line. --}}
        <section class="rx-card p-4 mb-4" aria-labelledby="rx-profile-title">
            <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-profile-title">
                <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.profile_title') }}
            </h2>

            @if (!$profile)
                <p class="text-gray-700 mb-0">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.pharmacist.profile_no_consent') }}
                </p>
            @else
                <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.profile_allergies') }}</h3>
                @if ($allergies !== [])
                    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-4">
                        @foreach ($allergies as $allergy)
                            <li class="rx-badge rx-badge--emergency">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>{{ $allergy }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="small text-gray-600 mb-4">{{ __('messages.pharmacist.answer_empty') }}</p>
                @endif

                <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.profile_chronic') }}</h3>
                @if ($chronic !== [])
                    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-4">
                        @foreach ($chronic as $condition)
                            <li class="rx-badge rx-badge--caution">
                                <i class="bi bi-activity" aria-hidden="true"></i>{{ $condition }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="small text-gray-600 mb-4">{{ __('messages.pharmacist.answer_empty') }}</p>
                @endif

                <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.profile_medications') }}</h3>
                @if ($medications !== [])
                    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-4">
                        @foreach ($medications as $medication)
                            <li class="rx-badge">
                                <i class="bi bi-prescription2" aria-hidden="true"></i>{{ $medication }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="small text-gray-600 mb-4">{{ __('messages.pharmacist.answer_empty') }}</p>
                @endif

                <dl class="rx-dl row mb-2">
                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_pregnant') }}</dt>
                    <dd class="col-sm-7">
                        <span class="rx-badge rx-badge--{{ $profile->is_pregnant ? 'emergency' : 'calm' }}">
                            <i class="bi {{ $profile->is_pregnant ? 'bi-exclamation-triangle-fill' : 'bi-dash-circle' }}" aria-hidden="true"></i>
                            {{ $profile->is_pregnant ? __('messages.pharmacist.profile_yes') : __('messages.pharmacist.profile_no') }}
                        </span>
                    </dd>

                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_breastfeeding') }}</dt>
                    <dd class="col-sm-7">
                        <span class="rx-badge rx-badge--{{ $profile->is_breastfeeding ? 'emergency' : 'calm' }}">
                            <i class="bi {{ $profile->is_breastfeeding ? 'bi-exclamation-triangle-fill' : 'bi-dash-circle' }}" aria-hidden="true"></i>
                            {{ $profile->is_breastfeeding ? __('messages.pharmacist.profile_yes') : __('messages.pharmacist.profile_no') }}
                        </span>
                    </dd>

                    @if ($profile->weight_kg)
                        <dt class="col-sm-5">{{ __('messages.pharmacist.profile_weight') }}</dt>
                        <dd class="col-sm-7">{{ $profile->weight_kg }}</dd>
                    @endif

                    @if ($profile->notes)
                        <dt class="col-sm-5">{{ __('messages.pharmacist.profile_notes') }}</dt>
                        <dd class="col-sm-7 mb-0">{{ $profile->notes }}</dd>
                    @endif
                </dl>

                <p class="small text-gray-600 mb-0">
                    <i class="bi bi-journal-text me-1" aria-hidden="true"></i>{{ __('messages.pharmacist.profile_accessed_note') }}
                </p>
            @endif
        </section>

        {{-- Prior sessions for this customer. Quiet inline empty state, not an empty
             bordered card: an empty card reads as a card that failed to load. --}}
        @if ($pastSessions->isNotEmpty())
            <section class="rx-card p-4 mb-4" aria-labelledby="rx-history-title">
                <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-history-title">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.past_sessions_title') }}
                </h2>
                <ul class="list-unstyled mb-0">
                    @foreach ($pastSessions as $past)
                        @php
                            $pastOutcome = $outcomeMeta[$past->outcome] ?? $outcomeMeta['pharmacist_review'];
                            $pastSymptom = $past->collected_data['raw_symptom_text']
                                ?? implode(', ', (array) ($past->collected_data['symptoms'] ?? []));
                            $isReviewed = $past->status === 'reviewed';
                        @endphp
                        <li class="{{ $loop->first ? '' : 'border-top pt-3 mt-3' }}">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <time datetime="{{ $past->created_at->toIso8601String() }}" class="fw-semibold text-gray-900">
                                    {{ __('messages.pharmacist.past_session_date', ['date' => $past->created_at->format('d/m/Y')]) }}
                                </time>
                                <span class="rx-badge rx-badge--{{ $pastOutcome['severity'] }}">
                                    <i class="bi {{ $pastOutcome['icon'] }}" aria-hidden="true"></i>{{ $pastOutcome['label'] }}
                                </span>
                                <span class="rx-badge rx-badge--{{ $isReviewed ? 'calm' : 'caution' }}">
                                    <i class="bi {{ $isReviewed ? 'bi-check2-circle' : 'bi-hourglass-split' }}" aria-hidden="true"></i>
                                    {{ $isReviewed ? __('messages.pharmacist.status_reviewed') : __('messages.pharmacist.status_pending') }}
                                </span>
                            </div>
                            <p class="small text-gray-700 mb-0">
                                {{ $pastSymptom !== '' ? $pastSymptom : __('messages.pharmacist.no_symptom') }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            <p class="small text-gray-600 mb-4">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>{{ __('messages.pharmacist.past_sessions_empty') }}
            </p>
        @endif

        <section class="rx-card p-4 mb-4" aria-labelledby="rx-answers-title">
            <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-answers-title">
                <i class="bi bi-chat-left-text me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.answers_title') }}
            </h2>

            @if (empty($answers))
                <p class="text-gray-600 mb-0">{{ __('messages.pharmacist.answer_empty') }}</p>
            @else
                <dl class="rx-dl row mb-0">
                    @foreach ($answers as $label => $value)
                        <dt class="col-sm-5">{{ $label }}</dt>
                        <dd class="col-sm-7">{{ $value }}</dd>
                    @endforeach
                </dl>
            @endif

            @if (!empty($skipped))
                <p class="small text-gray-600 mb-0 mt-2">
                    <i class="bi bi-slash-circle me-1" aria-hidden="true"></i>
                    {{ __('messages.pharmacist.answer_skipped') }}: {{ implode(', ', $skipped) }}
                </p>
            @endif
        </section>

        @if ($session->advice_given || $session->safety_net)
            <section class="rx-card p-4" aria-labelledby="rx-advice-title">
                <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-advice-title">
                    <i class="bi bi-cpu me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.advice_title') }}
                </h2>

                @if ($session->advice_given)
                    <div class="text-gray-900 mb-3" style="white-space: pre-line; line-height: 1.7;">{{ $session->advice_given }}</div>
                @endif

                @if ($session->safety_net)
                    <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.safety_net_title') }}</h3>
                    <p class="text-gray-900 mb-0" style="line-height: 1.7;">{{ $session->safety_net }}</p>
                @endif
            </section>
        @endif
    </div>

    {{-- Action panel, in the order the pharmacist works through it:
         claim -> AI draft -> reply -> escalate. --}}
    <div class="col-12 col-lg-5">
        @if (!$isPending)
            <div class="alert alert-secondary d-flex align-items-start gap-2" role="status">
                <i class="bi bi-lock-fill mt-1" aria-hidden="true"></i>
                <div>
                    {{ __('messages.pharmacist.error_already_reviewed') }}
                    @if ($session->pharmacist_note)
                        <div class="mt-2" style="white-space: pre-line;">{{ $session->pharmacist_note }}</div>
                    @endif
                </div>
            </div>
        @else
            @if ($session->pharmacist_id)
                <p class="small text-gray-700">
                    <i class="bi bi-person-check me-1" aria-hidden="true"></i>
                    {{ $session->pharmacist_id === auth()->id()
                        ? __('messages.pharmacist.claimed_by_you')
                        : __('messages.pharmacist.claimed_by', ['name' => $session->pharmacist?->name ?? '']) }}
                </p>
            @else
                <form method="POST" action="{{ route('pharmacist.triage.claim', $session->MaSL) }}" class="mb-4">
                    @csrf
                    <button type="submit" class="rx-btn rx-btn-ghost">
                        <i class="bi bi-hand-index-thumb" aria-hidden="true"></i>{{ __('messages.pharmacist.btn_claim') }}
                    </button>
                </form>
            @endif

            <section class="rx-card p-4 mb-4" aria-labelledby="rx-reply-title">
                <h2 class="h6 fw-semibold text-gray-800 mb-2" id="rx-reply-title">
                    <i class="bi bi-reply-fill me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.reply_title') }}
                </h2>
                <p class="small text-gray-600">{{ __('messages.pharmacist.reply_hint') }}</p>

                {{-- Sibling form, not nested: nesting forms is invalid HTML. Posts to a
                     separate route that only prefills the textarea via old(), so nothing
                     machine-written is ever persisted or sent to the customer. --}}
                @if ($aiDraftEnabled)
                    <form method="POST" action="{{ route('pharmacist.triage.draft', $session->MaSL) }}" class="mb-3">
                        @csrf
                        <button type="submit" class="rx-btn rx-btn-ghost">
                            <i class="bi bi-stars" aria-hidden="true"></i>{{ __('messages.pharmacist.btn_ai_draft') }}
                        </button>
                        <p class="small text-gray-600 mt-2 mb-0">{{ __('messages.pharmacist.ai_draft_hint') }}</p>
                    </form>

                    @if (session('success') === __('messages.pharmacist.ai_draft_success'))
                        {{-- Icon + text, never colour alone. --}}
                        <p class="small mb-2 d-flex align-items-start gap-2" style="color: var(--health-refer);">
                            <i class="bi bi-robot flex-shrink-0" aria-hidden="true" style="margin-top: 2px;"></i>
                            <span>{{ __('messages.pharmacist.ai_draft_badge') }}</span>
                        </p>
                    @endif
                @endif

                <form method="POST" action="{{ route('pharmacist.triage.reply', $session->MaSL) }}">
                    @csrf
                    <label for="pharmacist_note" class="form-label fw-medium">{{ __('messages.pharmacist.reply_label') }}</label>
                    <textarea id="pharmacist_note"
                              name="pharmacist_note"
                              class="rx-textarea"
                              rows="6"
                              maxlength="2000"
                              required
                              placeholder="{{ __('messages.pharmacist.reply_placeholder') }}">{{ old('pharmacist_note') }}</textarea>

                    <div class="rx-actions mt-3">
                        <button type="submit" class="rx-btn rx-btn-primary">
                            <i class="bi bi-send-fill" aria-hidden="true"></i>{{ __('messages.pharmacist.btn_reply') }}
                        </button>
                    </div>
                </form>
            </section>

            {{-- Escalation lives in its own card with a warning tone: it ends the case
                 and strips every product suggestion, so it must not sit next to "reply". --}}
            <section class="rx-card p-4"
                     style="border-color: var(--health-refer-border); background: var(--health-refer-bg);"
                     aria-labelledby="rx-escalate-title">
                <h2 class="h6 fw-semibold mb-2" id="rx-escalate-title" style="color: var(--health-refer);">
                    <i class="bi bi-hospital-fill me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.escalate_title') }}
                </h2>
                <p class="small mb-3" style="color: #78350F;">{{ __('messages.pharmacist.escalate_hint') }}</p>

                <form method="POST" action="{{ route('pharmacist.triage.escalate', $session->MaSL) }}">
                    @csrf
                    <label for="escalate_note" class="form-label fw-medium" style="color: #78350F;">
                        {{ __('messages.pharmacist.escalate_label') }}
                    </label>
                    <textarea id="escalate_note"
                              name="pharmacist_note"
                              class="rx-textarea"
                              rows="4"
                              maxlength="2000"
                              required
                              placeholder="{{ __('messages.pharmacist.escalate_placeholder') }}"></textarea>

                    <div class="rx-actions mt-3">
                        <button type="submit" class="rx-btn rx-btn-warning">
                            <i class="bi bi-arrow-up-right-circle-fill" aria-hidden="true"></i>{{ __('messages.pharmacist.btn_escalate') }}
                        </button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</div>
@endsection
