@extends('layouts.app')

@section('title', __('messages.health.page_title') . ' - ' . __('messages.app_name'))

@push('styles')
    @include('health.partials.styles')
@endpush

@section('content')
@php
    // Both AI seams write into their own columns after the outcome is final, so the
    // page is complete without either. `pending` is only true while a worker is
    // still writing; `unavailable` means one was due but produced nothing.
    $briefingPending = (bool) $briefingJobId;
    $briefingUnavailable = $briefing === null && !$briefingPending && $briefingExpected;
@endphp

<div class="health-page py-4 py-md-5">
    <div class="container">
        <div class="health-shell">

            {{-- Clinical header: breadcrumb eyebrow + calm title + audit timestamp --}}
            <header class="text-center mb-4 mb-md-5">
                <nav aria-label="{{ __('messages.health.nav_full') }}" class="mb-3">
                    <span class="health-eyebrow">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        {{ __('messages.health.nav_full') }}
                    </span>
                </nav>
                <h1 class="mb-2">{{ __('messages.health.page_title') }}</h1>
                <p class="small mb-0 d-inline-flex align-items-center gap-1" style="color: var(--health-ink-mute);">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>{{ $session->created_at->format('d/m/Y H:i') }}</span>
                </p>
            </header>

            @include('health.partials.disclaimer', ['disclaimerReviewable' => $disclaimerReviewable])

            @if ($showDisclaimerPopup ?? false)
                @include('health.partials.disclaimer-popup', ['MaSL' => $session->MaSL])
            @endif

            @if ($aiJobId)
                {{-- The conclusion below is already final; only the friendlier wording is
                     still being written. Saying so is honest and stops the patient
                     wondering whether the page is finished loading. --}}
                <div class="bg-white rounded-3 border p-3 mt-4 d-flex align-items-center gap-3 health-no-print"
                     id="triage-ai-pending">
                    {{-- role=status on the text, aria-hidden on the spinner: sharing one
                         element cancels the live region, and a frozen spinner under
                         reduced motion conveys nothing. --}}
                    <span class="spinner-border spinner-border-sm text-secondary" aria-hidden="true"></span>
                    <span class="small text-gray-700" role="status">{{ __('messages.health.ai_pending') }}</span>
                </div>
            @endif

            <div class="mt-4">
                @include('health.partials.outcome', [
                    'session' => $session,
                    'briefing' => $briefing,
                    'suggestedProducts' => $suggestedProducts,
                ])
            </div>

            {{-- The detailed explanation. Placed directly under the outcome because it
                 is what the customer came for: the conclusion alone answers "what",
                 this answers "why" and "what now". --}}
            @if ($briefing !== null)
                @include('health.partials.briefing', [
                    'briefing' => $briefing,
                    'flaggedProducts' => $safety['flagged'] ?? [],
                ])
            @elseif ($briefingPending)
                @include('health.partials.briefing-pending')
                {{-- Pre-rendered hidden: when the poller learns the job died there is
                     nothing left to fetch, so it unhides this instead of emptying the
                     slot. --}}
                @include('health.partials.briefing-unavailable', ['hidden' => true])
            @elseif ($briefingUnavailable)
                @include('health.partials.briefing-unavailable')
            @endif

            {{-- Why this conclusion. Every value here is already persisted on the
                 session; nothing is re-derived and no medical content is invented.
                 Collapsed by default now that the briefing above explains the same
                 conclusion in prose — this is the audit trail, not the answer. --}}
            <details class="health-card health-details rounded-4 border-0 mt-4">
                <summary class="health-details__summary">
                    <span class="health-details__summary-text">
                        <i class="bi bi-list-check" aria-hidden="true"></i>{{ __('messages.health.basis_title') }}
                    </span>
                    <i class="bi bi-chevron-down health-details__chevron" aria-hidden="true"></i>
                </summary>

                <div class="health-details__body">
                    <dl class="health-basis-list mb-3">
                        @if (!empty($basis['symptoms']))
                            <div>
                                <dt>{{ __('messages.health.basis_symptoms') }}</dt>
                                <dd>{{ implode(', ', $basis['symptoms']) }}</dd>
                            </div>
                        @endif

                        @if ($basis['duration_days'] !== null)
                            <div>
                                <dt>{{ __('messages.health.basis_duration') }}</dt>
                                <dd>
                                    {{ (int) $basis['duration_days'] <= 1
                                        ? __('messages.health.basis_duration_today')
                                        : __('messages.health.basis_duration_days', ['days' => (int) $basis['duration_days']]) }}
                                </dd>
                            </div>
                        @endif

                        @if ($basis['age'] !== null)
                            <div>
                                <dt>{{ __('messages.health.basis_age') }}</dt>
                                <dd>{{ __('messages.health.basis_age_years', ['age' => (int) $basis['age']]) }}</dd>
                            </div>
                        @endif

                        @if ($basis['clarify_rounds'] > 0)
                            <div>
                                <dt>{{ __('messages.health.basis_clarify') }}</dt>
                                <dd>{{ __('messages.health.basis_clarify_rounds', ['count' => $basis['clarify_rounds']]) }}</dd>
                            </div>
                        @endif
                    </dl>

                    {{-- "No red flag found" is a result, not an empty state: it is the
                         reason self-care was allowed at all. --}}
                    @if (!empty($basis['red_flags']))
                        <p class="small fw-medium text-gray-700 mb-2">{{ __('messages.health.basis_red_flags_found') }}</p>
                        <ul class="list-unstyled d-flex flex-wrap gap-2 mb-3">
                            @foreach ($basis['red_flags'] as $flag)
                                <li class="badge bg-white text-gray-800 border px-3 py-2 rounded-pill fw-normal">
                                    <i class="bi bi-dot" aria-hidden="true"></i>{{ $flag['name_vi'] ?? '' }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small text-gray-700 mb-3">
                            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ __('messages.health.basis_red_flags_cleared') }}
                        </p>
                    @endif

                    <p class="small text-gray-600 mb-0">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.basis_note') }}
                    </p>
                </div>
            </details>

            {{-- What the user told us, so the printout stands alone at the clinic --}}
            @if (!empty($session->collected_data))
                <details class="health-card health-details rounded-4 border-0 mt-4">
                    <summary class="health-details__summary">
                        <span class="health-details__summary-text">
                            <i class="bi bi-chat-left-text" aria-hidden="true"></i>{{ __('messages.health.answers_title') }}
                        </span>
                        <i class="bi bi-chevron-down health-details__chevron" aria-hidden="true"></i>
                    </summary>

                    <div class="health-details__body">
                        <dl class="health-answer-list row g-3 mb-0">
                            @php
                                $data = $session->collected_data;
                                $rows = [
                                    __('messages.health.step_1') => $data['raw_symptom_text'] ?? ($data['symptoms'] ?? null),
                                    __('messages.health.step_2') => isset($data['duration_days']) && $data['duration_days'] !== null
                                        ? $data['duration_days'] . ' ngày' : null,
                                    __('messages.health.step_3') => $data['medications_taken'] ?? null,
                                    __('messages.health.step_4') => $data['history'] ?? null,
                                    __('messages.health.step_5') => $data['other_symptoms'] ?? null,
                                ];
                            @endphp

                            @foreach ($rows as $label => $value)
                                @if (!empty($value))
                                    <div class="col-12 col-sm-6">
                                        <dt>{{ $label }}</dt>
                                        <dd class="mb-0">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>

                        {{-- Follow-up rounds belong on the printout too — they are often the
                             answers that decided the outcome — but kept in their own group so
                             they are not mistaken for the five wizard steps. --}}
                        @if ($session->clarifyHistory() !== [])
                            <h4 class="small fw-semibold text-gray-700 mt-4 mb-2">
                                <i class="bi bi-robot me-1" aria-hidden="true"></i>{{ __('messages.health.answers_clarify_title') }}
                            </h4>
                            <dl class="health-answer-list row g-3 mb-0">
                                @foreach ($session->clarifyHistory() as $pair)
                                    <div class="col-12 col-sm-6">
                                        <dt>{{ $pair['question'] ?: __('messages.health.clarify_title') }}</dt>
                                        <dd class="mb-0">{{ $pair['answer'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </details>
            @endif

            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center mt-4 health-no-print">
                <button type="button" class="health-btn health-btn-ghost" onclick="window.print()">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>{{ __('messages.health.btn_print') }}
                </button>

                <form method="POST" action="{{ route('health.restart') }}">
                    @csrf
                    <button type="submit" class="health-btn health-btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>{{ __('messages.health.btn_new_check') }}
                    </button>
                </form>

                <a href="{{ route('health.history') }}" class="health-btn health-btn-ghost text-decoration-none text-center">
                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i>{{ __('messages.health.btn_history') }}
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@php
    // Built here rather than inline in @json: Blade parses a directive's argument as a
    // single balanced expression, and a multi-line nested array breaks that parse.
    //
    // The two jobs are polled independently because their latencies differ by an order
    // of magnitude — the advice rewrite is one short call, the briefing asks for six
    // sections — so waiting for both before reloading would hide the faster one.
    $pollJobs = array_values(array_filter([
        $aiJobId ? ['url' => route('ai-jobs.poll', $aiJobId), 'banner' => 'triage-ai-pending'] : null,
        $briefingJobId
            ? [
                'url' => route('ai-jobs.poll', $briefingJobId),
                'banner' => 'triage-briefing-pending',
                // Unhidden instead of just dropping the skeleton: this banner is the
                // content, so an empty slot would read as a stalled page.
                'fallback' => 'triage-briefing-unavailable',
            ]
            : null,
    ]));
@endphp

@if ($pollJobs !== [])
    @push('scripts')
        <script>
            // Poll for the deferred AI writes and reload once they land. Progressive
            // enhancement: with JavaScript off the page shows the pharmacist-written
            // advice, which is the authoritative text anyway.
            (function () {
                const jobs = @json($pollJobs);

                jobs.forEach((job) => {
                    const banner = document.getElementById(job.banner);
                    const fallback = job.fallback ? document.getElementById(job.fallback) : null;
                    // ~2 minutes at 1.5s: past the worst case of primary + fallback
                    // provider on the slower of the two calls.
                    let attempts = 0;

                    function stop() {
                        if (banner) {
                            banner.remove();
                        }

                        // The advice banner has none: its text is already on the page, so
                        // removing it leaves nothing missing.
                        if (fallback) {
                            fallback.hidden = false;
                        }
                    }

                    function check() {
                        if (attempts++ > 90) {
                            stop();
                            return;
                        }

                        fetch(job.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                            .then((state) => {
                                if (state.status === 'done' || state.stranded) {
                                    // A finished job or a worker that never started: reload so the
                                    // finished columns paint, or so the controller can run the
                                    // synchronous fallback inline matching triage.js.
                                    window.location.reload();
                                    return;
                                }

                                if (state.status === 'failed') {
                                    stop();
                                    return;
                                }

                                window.setTimeout(check, 1500);
                            })
                            .catch(stop);
                    }

                    window.setTimeout(check, 1200);
                });
            })();
        </script>
    @endpush
@endif
