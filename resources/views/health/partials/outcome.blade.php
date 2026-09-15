@php
    // Colour + icon + label per severity level. Never colour alone.
    $outcomeMeta = [
        'emergency' => ['icon' => 'bi-exclamation-octagon-fill', 'label' => __('messages.health.outcome_emergency'), 'lead' => __('messages.health.outcome_emergency_lead')],
        'refer_doctor' => ['icon' => 'bi-hospital-fill', 'label' => __('messages.health.outcome_refer_doctor'), 'lead' => __('messages.health.outcome_refer_doctor_lead')],
        'pharmacist_review' => ['icon' => 'bi-person-badge-fill', 'label' => __('messages.health.outcome_pharmacist_review'), 'lead' => __('messages.health.outcome_pharmacist_review_lead')],
        'self_care' => ['icon' => 'bi-house-heart-fill', 'label' => __('messages.health.outcome_self_care'), 'lead' => __('messages.health.outcome_self_care_lead')],
    ];

    $outcome = $session->outcome ?? 'pharmacist_review';
    $meta = $outcomeMeta[$outcome] ?? $outcomeMeta['pharmacist_review'];
    $isSelfCare = $outcome === 'self_care';

    $safety = $safety ?? ['warnings' => [], 'flagged' => []];
    $hasProfile = $hasProfile ?? false;
@endphp

{{-- The conclusion is stated once, in the page header. Repeating the severity card
     here said the same sentence a second time and pushed the first real content off
     the fold. What is left in this block is only what the card carried and the
     header does not: the emergency action, the red flags that justified it, and the
     pharmacist-written advice. --}}

{{-- Emergency: calling 115 is the ONLY primary action on the page.
     No competing buttons, no product links. --}}

@if ($session->advice_given)
    <section class="health-brief" aria-labelledby="advice-heading">
        <div class="health-brief__head">
            <span class="health-icon-tile" aria-hidden="true">
                <i class="bi bi-clipboard2-heart"></i>
            </span>
            <div>
                <h2 class="mb-1" id="advice-heading">
                    {{ $isSelfCare ? __('messages.health.advice_title') : __('messages.health.guidance_title') }}
                </h2>
                <p class="small mb-0" style="color: var(--health-ink-soft);">
                    {{ __('messages.health.advice_hint') }}
                </p>
            </div>
        </div>

        {{-- The AI rewrite used to render here as a second, friendlier copy of the
             same advice, with the pharmacist's original one click behind it. Two
             versions of one instruction on one page is a contradiction waiting to be
             read as two different pieces of advice, so only the authoritative one
             stays. --}}
        <ul class="health-brief__list">
            @foreach (preg_split('/\r\n|\r|\n/', trim($session->advice_given)) as $line)
                @continue(trim($line) === '')
                <li>{{ trim($line) }}</li>
            @endforeach
        </ul>
    </section>
@endif

{{-- Pharmacist reply, once a human has answered. The queue is opt-in, so the
     waiting state only appears after the customer asked for it — see the
     request card further down. Shown for any reviewed outcome, not just
     pharmacist_review: escalation rewrites the outcome to refer_doctor. --}}
@if ($session->pharmacist_note)
    <div class="bg-white rounded-3 border p-3 p-md-4 mb-4">
        <p class="small fw-semibold text-gray-700 mb-2">
            <i class="bi bi-person-badge-fill me-1" aria-hidden="true"></i>
            {{ __('messages.health.pharmacist_answer_by', ['name' => $session->pharmacist?->name ?? '']) }}
        </p>
        <div class="text-gray-800" style="white-space: pre-line;">{{ $session->pharmacist_note }}</div>
    </div>
@elseif ($session->status === 'pending_pharmacist')
    <div class="bg-white rounded-3 border p-3 d-flex align-items-center gap-3 mb-4">
        {{-- text-info (#0DCAF0) measured 1.96:1 on white as the sole waiting graphic.
             The live region is the text, not the spinner: under reduced motion the
             animation is frozen, so the words have to carry the state. --}}
        <span class="spinner-border spinner-border-sm" aria-hidden="true"
              style="color: var(--health-pharmacist);"></span>
        <span class="small text-gray-700" role="status">{{ __('messages.health.pharmacist_waiting') }}</span>
    </div>
@endif

{{-- Dropped from this block at the customer's request: the possible-condition guesses,
     the safety-net escalation threshold, and the follow-up reminder. The first was a
     probability nobody acts on; the last two are duplicated by the briefing's own
     "theo dõi" and "khi nào đi khám" sections. --}}

{{-- Side channel, deliberately last and deliberately opt-in: the answer above is
     the result, a pharmacist is brought in only when the customer asks. Hidden for
     emergency, where the only action is calling 115. --}}
@if ($outcome !== 'emergency')
    @if ($session->pharmacist_note)
        {{-- Already answered by a human; the note is rendered above. --}}
    @elseif ($session->status === 'pending_pharmacist')
        <p class="small text-gray-600 text-center mb-4 health-no-print">
            <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>{{ __('messages.health.pharmacist_request_pending') }}
        </p>
    @elseif ($session->status === 'completed')
        <section class="health-brief health-no-print" aria-labelledby="pharmacist-request-heading">
            <div class="health-brief__head">
                <span class="health-icon-tile health-icon-tile--accent" aria-hidden="true">
                    <i class="bi bi-person-badge"></i>
                </span>
                <div>
                    <h2 class="mb-1" id="pharmacist-request-heading">{{ __('messages.health.pharmacist_request_title') }}</h2>
                    <p class="small mb-0" style="color: var(--health-ink-soft);">
                        {{ __('messages.health.pharmacist_request_hint') }}
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('health.requestPharmacist', $session->MaSL) }}" class="mt-3">
                @csrf
                <button type="submit" class="health-btn health-btn-ghost w-100">
                    {{ __('messages.health.pharmacist_request_btn') }}
                    <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                </button>
            </form>
        </section>
    @endif
@endif
