@extends('pharmacist.layout')

@section('title', __('messages.pharmacist.detail_title', ['id' => $session->MaSL]))

@section('content')
@php
    $outcomeMeta = [
        'emergency' => ['icon' => 'bi-exclamation-octagon-fill', 'label' => __('messages.health.outcome_emergency'), 'severity' => 'emergency'],
        'refer_doctor' => ['icon' => 'bi-hospital-fill', 'label' => __('messages.health.outcome_refer_doctor'), 'severity' => 'urgent'],
        'pharmacist_review' => ['icon' => 'bi-person-badge-fill', 'label' => __('messages.health.outcome_pharmacist_review'), 'severity' => 'caution'],
        'self_care' => ['icon' => 'bi-house-heart-fill', 'label' => __('messages.health.outcome_self_care'), 'severity' => 'calm'],
    ];
    $outcome = $outcomeMeta[$session->outcome] ?? $outcomeMeta['pharmacist_review'];
    $flagIcons = [
        'emergency' => 'bi-exclamation-octagon-fill',
        'urgent' => 'bi-hospital-fill',
        'caution' => 'bi-person-badge-fill',
    ];
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

@error('pharmacist_note')
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
        <div>{{ $message }}</div>
    </div>
@enderror

<div class="row g-4">
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

        <section class="rx-card p-4" aria-labelledby="rx-system-title">
            <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-system-title">
                <i class="bi bi-cpu me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.system_outcome_title') }}
            </h2>

            <span class="rx-badge rx-badge--{{ $outcome['severity'] }} mb-3">
                <i class="bi {{ $outcome['icon'] }}" aria-hidden="true"></i>{{ $outcome['label'] }}
            </span>

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
                <p class="text-gray-900">{{ implode(', ', $symptomNames) }}</p>
            @endif

            @if ($session->advice_given)
                <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.advice_title') }}</h3>
                <div class="text-gray-900 mb-3" style="white-space: pre-line; line-height: 1.7;">{{ $session->advice_given }}</div>
            @endif

            @if ($session->safety_net)
                <h3 class="small fw-semibold text-gray-700 mb-2">{{ __('messages.pharmacist.safety_net_title') }}</h3>
                <p class="text-gray-900 mb-0" style="line-height: 1.7;">{{ $session->safety_net }}</p>
            @endif
        </section>
    </div>

    <div class="col-12 col-lg-5">
        <section class="rx-card p-4 mb-4" aria-labelledby="rx-profile-title">
            <h2 class="h6 fw-semibold text-gray-800 mb-3" id="rx-profile-title">
                <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>{{ __('messages.pharmacist.profile_title') }}
            </h2>

            @if (!$profile)
                <p class="text-gray-700 mb-0">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.pharmacist.profile_no_consent') }}
                </p>
            @else
                <dl class="rx-dl row mb-2">
                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_allergies') }}</dt>
                    <dd class="col-sm-7">{{ implode(', ', (array) ($profile->allergies ?? [])) ?: __('messages.pharmacist.answer_empty') }}</dd>

                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_chronic') }}</dt>
                    <dd class="col-sm-7">{{ implode(', ', (array) ($profile->chronic_conditions ?? [])) ?: __('messages.pharmacist.answer_empty') }}</dd>

                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_medications') }}</dt>
                    <dd class="col-sm-7">{{ implode(', ', (array) ($profile->current_medications ?? [])) ?: __('messages.pharmacist.answer_empty') }}</dd>

                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_pregnant') }}</dt>
                    <dd class="col-sm-7">{{ $profile->is_pregnant ? __('messages.pharmacist.profile_yes') : __('messages.pharmacist.profile_no') }}</dd>

                    <dt class="col-sm-5">{{ __('messages.pharmacist.profile_breastfeeding') }}</dt>
                    <dd class="col-sm-7">{{ $profile->is_breastfeeding ? __('messages.pharmacist.profile_yes') : __('messages.pharmacist.profile_no') }}</dd>

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
