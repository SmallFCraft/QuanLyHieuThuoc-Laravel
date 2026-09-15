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

    // The conclusion is labelled in exactly one place per surface: the header names
    // it, the journey rail closes on it. Both read this map so neither can drift.
    $outcome = $session->outcome ?? 'pharmacist_review';
    $outcomeLabels = [
        'emergency' => __('messages.health.outcome_emergency'),
        'refer_doctor' => __('messages.health.outcome_refer_doctor'),
        'pharmacist_review' => __('messages.health.outcome_pharmacist_review'),
        'self_care' => __('messages.health.outcome_self_care'),
    ];

    // Same four marks outcome.blade.php and the journey rail use. One table, so the
    // header cannot end up disagreeing with the card about what a level looks like.
    $outcomeIcons = [
        'emergency' => 'bi-exclamation-octagon-fill',
        'refer_doctor' => 'bi-hospital-fill',
        'pharmacist_review' => 'bi-person-badge-fill',
        'self_care' => 'bi-house-heart-fill',
    ];

    $hasProducts = !empty($suggestedProducts) || !empty(data_get($briefing, 'products', []));

    // Three panels hold what the page used to stack: the AI's prose, the medicines,
    // and the audit trail. An emergency page renders all three flat — Bootstrap hides
    // an inactive pane, and a red-flag page must not put the reason behind a control.
    $tabs = [
        ['id' => 'briefing', 'icon' => 'bi-stars', 'label' => __('messages.health.briefing_title')],
        ['id' => 'care', 'icon' => 'bi-capsule', 'label' => __('messages.health.hub_tab_care')],
        ['id' => 'audit', 'icon' => 'bi-list-check', 'label' => __('messages.health.hub_tab_audit')],
    ];
    $useTabs = !$session->isEmergency();
@endphp

<div class="health-page py-4 py-md-5">
    <div class="container">
        {{-- `health-shell--rail`: the journey rail is a sticky column beside the content
             on wide screens, so this page's shell is wider than the wizard's. --}}
        <div class="health-shell health-shell--rail">
            @include('health.partials.journey', [
                'outcome' => $outcome,
                'outcomeLabel' => $outcomeLabels[$outcome] ?? '',
            ])

            {{-- One cell for everything that is not the rail. Wrapping is not cosmetic:
                 without it each child is its own implicit grid row, and a rail pinned
                 across them all stretches row 1 to the rail's height, leaving a hole
                 under the header. One wrapper, one row, no hole. --}}
            <div class="health-shell__content">
            {{-- Clinical masthead. Four centred lines stacked the section name, the page
                 title and the conclusion on top of each other, and gave the quietest
                 treatment to the one line the customer came to read. The layout is
                 left-aligned now, like a record rather than a banner: context, then the
                 answer at the scale it deserves, then when it was taken. --}}
            <header class="mb-4">
                <div class="health-masthead">
                    <span class="health-eyebrow">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        {{ __('messages.health.nav_full') }}
                    </span>
                    <span class="health-masthead__stamp">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <time datetime="{{ $session->created_at->toIso8601String() }}">
                            {{ $session->created_at->format('d/m/Y H:i') }}
                        </time>
                    </span>
                </div>

                <h1 class="mb-0">{{ __('messages.health.page_title') }}</h1>

                {{-- The conclusion, at the scale of a result. Colour, icon and the word
                     "Kết luận" all carry it, so the severity survives greyscale. --}}
                <div class="health-verdict health-verdict--{{ $outcome }}">
                    <span class="health-verdict__icon" aria-hidden="true">
                        <i class="bi {{ $outcomeIcons[$outcome] ?? 'bi-shield-check' }}"></i>
                    </span>
                    <span class="health-verdict__body">
                        <span class="health-verdict__label">{{ __('messages.health.journey_outcome') }}</span>
                        <span class="health-verdict__value">{{ $outcomeLabels[$outcome] ?? '' }}</span>
                    </span>
                </div>
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

            {{-- The 115 action and the red flags that justified it render inside the rail
                 (journey.blade.php), above the journey list. An emergency page is flat
                 below the header, so nothing sits between a red-flag patient and 115;
                 the care tab carries no duplicate. --}}

            <div class="health-hub mt-4" @if ($useTabs) data-triage-hub @endif>
                @if ($useTabs)
                    <nav class="health-hub__tabbar health-no-print" role="tablist" aria-label="{{ __('messages.health.hub_aria') }}">
                        @foreach ($tabs as $index => $tab)
                            <a href="#hub-{{ $tab['id'] }}"
                               class="health-hub__tab {{ $index === 0 ? 'active' : '' }}"
                               data-bs-toggle="pill"
                               role="tab"
                               aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                                <i class="bi {{ $tab['icon'] }}" aria-hidden="true"></i>
                                <span>{{ $tab['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                @endif

                <div class="health-hub__panels">
                    <div class="tab-pane {{ $useTabs ? 'fade show active' : '' }}" id="hub-briefing" role="tabpanel">
                        @if ($briefing !== null)
                            @include('health.partials.briefing', [
                                'briefing' => $briefing,
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
                    </div>

                    <div class="tab-pane {{ $useTabs ? 'fade' : '' }}" id="hub-care" role="tabpanel">
                        @include('health.partials.outcome', [
                            'session' => $session,
                            'briefing' => $briefing,
                            'safety' => $safety,
                            'hasProfile' => $hasProfile,
                            'suggestedProducts' => $suggestedProducts,
                        ])

                        {{-- The grid and its heading share one shell, so the tab has a
                             second block rather than a heading floating between
                             paragraphs. Heading only where medicine can appear: a
                             referral says outright that none matches. --}}
                        @if ($hasProducts)
                            <section class="health-brief" aria-labelledby="products-heading">
                                <div class="health-brief__head">
                                    <span class="health-icon-tile" aria-hidden="true">
                                        <i class="bi bi-capsule"></i>
                                    </span>
                                    <div>
                                        <h2 class="mb-1" id="products-heading">{{ __('messages.health.products_title') }}</h2>
                                        <p class="small mb-0" style="color: var(--health-ink-soft);">
                                            {{ __('messages.health.products_note') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    @include('health.partials.product-list', [
                                        'session' => $session,
                                        'briefing' => $briefing,
                                        'safety' => $safety,
                                        'hasProfile' => $hasProfile,
                                        'suggestedProducts' => $suggestedProducts,
                                    ])
                                </div>
                            </section>
                        @else
                            <div class="mt-4">
                                @include('health.partials.product-list', [
                                    'session' => $session,
                                    'briefing' => $briefing,
                                    'safety' => $safety,
                                    'hasProfile' => $hasProfile,
                                    'suggestedProducts' => $suggestedProducts,
                                ])
                            </div>
                        @endif
                    </div>

                    <div class="tab-pane {{ $useTabs ? 'fade' : '' }}" id="hub-audit" role="tabpanel">
                        {{-- Both details open by default inside this panel. Collapsing them
                             made sense when they were the tail of a long column; here they
                             ARE the tab's content, and a tab holding two closed chevrons is
                             a tab that shows nothing. --}}
                        @include('health.partials.audit-trail', ['session' => $session, 'basis' => $basis])
                    </div>
                </div>
            </div>

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
            </div>{{-- .health-shell__content --}}
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
