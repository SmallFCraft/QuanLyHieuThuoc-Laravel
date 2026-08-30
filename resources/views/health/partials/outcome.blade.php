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

    // Optional so the pharmacist queue view can include this partial unchanged.
    $safety = $safety ?? ['warnings' => [], 'flagged' => []];
    $hasProfile = $hasProfile ?? false;
    $flaggedProducts = array_map('intval', $safety['flagged'] ?? []);
    // The AI briefing renders below this partial and may list its own in-stock
    // medicines, which changes what "no products" means here.
    $hasBriefingProducts = !empty(data_get($briefing ?? null, 'products'));
@endphp

<div class="health-outcome health-outcome--{{ $outcome }} p-4 p-md-5">
    <div class="d-flex align-items-start gap-3 mb-3">
        <i class="bi {{ $meta['icon'] }} fs-2 flex-shrink-0 health-outcome__title--{{ $outcome }}" aria-hidden="true"></i>
        <div>
            <h2 class="h4 fw-bold mb-1 health-outcome__title--{{ $outcome }}">{{ $meta['label'] }}</h2>
            <p class="mb-0 text-gray-800">{{ $meta['lead'] }}</p>
        </div>
    </div>

    {{-- Emergency: calling 115 is the ONLY primary action on the page.
         No competing buttons, no product links. --}}
    @if ($outcome === 'emergency')
        <a href="tel:115"
           class="health-btn health-btn-danger d-flex align-items-center justify-content-center gap-2 text-decoration-none w-100 mb-4"
           aria-label="{{ __('messages.health.call_emergency_aria') }}">
            <i class="bi bi-telephone-fill" aria-hidden="true"></i>
            <span>{{ __('messages.health.call_emergency') }}</span>
        </a>
    @endif

    @if (!empty($session->triggered_red_flags))
        <div class="mb-4">
            <h3 class="h6 fw-semibold text-gray-800 mb-2">{{ __('messages.health.red_flags_title') }}</h3>
            <ul class="list-unstyled mb-0 d-flex flex-wrap gap-2">
                @foreach ($session->triggered_red_flags as $flag)
                    {{-- white-space: normal — a ~255px pill inside a 303px column has no
                         headroom for a longer red-flag name. --}}
                    <li class="badge bg-white text-gray-800 border px-3 py-2 rounded-pill fw-normal"
                        style="white-space: normal; text-align: start;">
                        <i class="bi bi-dot" aria-hidden="true"></i>{{ $flag['name_vi'] ?? '' }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($session->advice_given)
        <div class="mb-4">
            <h3 class="h6 fw-semibold text-gray-800 mb-2">
                {{ $isSelfCare ? __('messages.health.advice_title') : __('messages.health.guidance_title') }}
            </h3>

            {{-- AI rewrite is a labelled sibling of the rule text, never a replacement.
                 The pharmacist-written original stays one click away and always prints. --}}
            @if ($session->ai_advice)
                <p class="health-ai-badge small mb-2">
                    <i class="bi bi-robot" aria-hidden="true"></i>
                    <span>{{ __('messages.health.ai_badge') }}</span>
                </p>
                <div class="text-gray-800" style="white-space: pre-line; line-height: 1.7;">{{ $session->ai_advice }}</div>
                <p class="small text-gray-600 mt-2 mb-0">{{ __('messages.health.ai_notice') }}</p>

                <details class="mt-2">
                    <summary class="small health-disclose">{{ __('messages.health.ai_original_toggle') }}</summary>
                    <div class="text-gray-800 mt-2" style="white-space: pre-line; line-height: 1.7;">{{ $session->advice_given }}</div>
                </details>
            @else
                <div class="text-gray-800" style="white-space: pre-line; line-height: 1.7;">{{ $session->advice_given }}</div>
            @endif
        </div>
    @endif

    {{-- Pharmacist reply, once a human has answered. The queue is opt-in, so the
         waiting state only appears after the customer asked for it — see the
         request card further down. Shown for any reviewed outcome, not just
         pharmacist_review: escalation rewrites the outcome to refer_doctor. --}}
    @if ($session->pharmacist_note)
        <div class="bg-white rounded-3 border p-3 p-md-4">
            <p class="small fw-semibold text-gray-700 mb-2">
                <i class="bi bi-person-badge-fill me-1" aria-hidden="true"></i>
                {{ __('messages.health.pharmacist_answer_by', ['name' => $session->pharmacist?->name ?? '']) }}
            </p>
            <div class="text-gray-800" style="white-space: pre-line;">{{ $session->pharmacist_note }}</div>
        </div>
    @elseif ($session->status === 'pending_pharmacist')
        <div class="bg-white rounded-3 border p-3 d-flex align-items-center gap-3">
            {{-- text-info (#0DCAF0) measured 1.96:1 on white as the sole waiting graphic.
                 The live region is the text, not the spinner: under reduced motion the
                 animation is frozen, so the words have to carry the state. --}}
            <span class="spinner-border spinner-border-sm" aria-hidden="true"
                  style="color: var(--health-pharmacist);"></span>
            <span class="small text-gray-700" role="status">{{ __('messages.health.pharmacist_waiting') }}</span>
        </div>
    @endif
</div>

@if ($isSelfCare)
    {{-- Condition group, with the confidence number spelled out in text --}}
    @if (!empty($session->possible_condition_groups))
        <div class="health-card rounded-4 border-0 p-4 p-md-5 mt-4">
            <h3 class="h6 fw-semibold text-gray-800 mb-3">{{ __('messages.health.condition_group_title') }}</h3>

            @foreach ($session->possible_condition_groups as $group)
                @php $percent = (int) round((($group['confidence'] ?? 0) * 100)); @endphp
                <div class="mb-3">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-baseline mb-1">
                        <span class="fw-medium text-gray-900">{{ $group['group'] ?? '' }}</span>
                        <span class="small text-gray-600">{{ __('messages.health.confidence_label') }}: {{ $percent }}%</span>
                    </div>
                    <div class="health-confidence-track"
                         role="progressbar"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"
                         aria-label="{{ __('messages.health.confidence_label') }} {{ $group['group'] ?? '' }}">
                        <div class="health-confidence-fill" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            @endforeach

            <p class="small text-gray-600 mb-0">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.condition_group_note') }}
            </p>
        </div>
    @endif

    {{-- Safety net comes BEFORE the product list on purpose: the user must read the
         limits of self-care before they see anything they can buy. --}}
    @if ($session->safety_net)
        <div class="health-safety-net p-4 mt-4">
            <h3 class="h6 fw-semibold mb-2 d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                {{ __('messages.health.safety_net_title') }}
            </h3>

            @if ($session->ai_safety_net)
                <p class="health-ai-badge small mb-2">
                    <i class="bi bi-robot" aria-hidden="true"></i>
                    <span>{{ __('messages.health.ai_badge') }}</span>
                </p>
                <p class="mb-2" style="line-height: 1.7;">{{ $session->ai_safety_net }}</p>
                <details>
                    <summary class="small health-disclose">{{ __('messages.health.ai_original_toggle') }}</summary>
                    <p class="mb-0 mt-2" style="line-height: 1.7;">{{ $session->safety_net }}</p>
                </details>
            @else
                <p class="mb-0" style="line-height: 1.7;">{{ $session->safety_net }}</p>
            @endif
        </div>
    @endif

    @if (!empty($session->suggested_products))
        <div class="health-card rounded-4 border-0 p-4 p-md-5 mt-4">
            <h3 class="h6 fw-semibold text-gray-800 mb-1">{{ __('messages.health.products_title') }}</h3>
            <p class="small text-gray-600 mb-4">{{ __('messages.health.products_note') }}</p>

            <div class="row g-3">
                @foreach ($suggestedProducts ?? $session->suggested_products as $product)
                    @php $isFlagged = in_array((int) ($product['id'] ?? 0), $flaggedProducts, true); @endphp
                    <div class="col-12 col-sm-6">
                        <div class="health-product h-100 d-flex flex-column {{ $isFlagged ? 'health-product--flagged' : '' }}">
                            @include('health.partials.product-media', ['product' => $product])

                            <p class="fw-semibold text-gray-900 mb-1">{{ $product['name'] ?? '' }}</p>

                            <p class="health-product__price mb-2">
                                {{ number_format((int) ($product['price'] ?? 0), 0, ',', '.') }} ₫
                                @if (!empty($product['category']))
                                    <span class="text-gray-600 fw-normal">· {{ $product['category'] }}</span>
                                @endif
                            </p>

                            {{-- Icon + text, never colour alone: this must survive greyscale printing. --}}
                            @if ($isFlagged)
                                <p class="health-product__flag small mb-2">
                                    <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                                    <span>{{ __('messages.health.product_has_warning') }}</span>
                                </p>
                            @endif

                            @if (!empty($product['usage']))
                                {{-- Full dosage line, not truncated: "uống 1 viên mỗi 4-6 giờ" is the
                                     part the customer actually needs. --}}
                                <p class="small text-gray-700 mb-2">
                                    <span class="text-gray-600">{{ __('messages.health.product_usage_label') }}:</span>
                                    {{ $product['usage'] }}
                                </p>
                            @endif

                            @php $stock = (int) ($product['stock_quantity'] ?? 0); @endphp
                            @if ($stock > 0)
                                <p class="small text-gray-600 mb-3">
                                    <i class="bi {{ $stock > 10 ? 'bi-check-circle' : 'bi-exclamation-circle' }} me-1" aria-hidden="true"></i>
                                    {{ $stock > 10 ? __('messages.health.product_stock_in') : __('messages.health.product_stock_low') }}
                                </p>
                            @endif

                            {{-- Four identical "Xem chi tiết" links would give a screen
                                 reader four identical accessible names. --}}
                            <a href="{{ $product['url'] ?? '#' }}"
                               class="health-btn health-btn-ghost mt-auto text-center text-decoration-none health-no-print"
                               aria-label="{{ __('messages.view_details') }}: {{ $product['name'] ?? '' }}">
                                {{ __('messages.view_details') }}
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- The cart blocks a contraindicated product one click later, so the warning
             has to appear where the suggestion is made, not only at checkout. --}}
        @if (!empty($safety['warnings']))
            <x-drug-warnings
                :warnings="$safety['warnings']"
                :title="__('messages.health.safety_check_title')"
                :intro="__('messages.health.safety_check_intro')"
                class="mt-4" />
        @elseif ($hasProfile)
            <p class="small text-gray-600 mt-3 mb-0">
                <i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ __('messages.health.safety_check_clear') }}
            </p>
        @else
            <p class="small text-gray-600 mt-3 mb-0 health-no-print">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                <a href="{{ route('profile.edit') }}" class="health-link health-link--accent p-0">
                    {{ __('messages.health.safety_check_no_profile') }}
                </a>
            </p>
        @endif
    @else
        {{-- Nothing matched the symptom. Saying so is better than the old behaviour,
             which quietly offered the four highest-stock items in the category. --}}
        <div class="health-card rounded-4 border-0 p-4 mt-4">
            <h3 class="h6 fw-semibold text-gray-800 mb-2">{{ __('messages.health.products_title') }}</h3>
            <p class="small text-gray-600 mb-0">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.products_empty') }}
            </p>
        </div>
    @endif

    @if ($session->follow_up_at)
        <p class="small text-gray-600 text-center mt-4 mb-0">
            <i class="bi bi-bell me-1" aria-hidden="true"></i>{{ __('messages.health.follow_up_note') }}
        </p>
    @endif
@else
    {{-- Explicit statement rather than an empty gap: no products is a decision.
         Suppressed when the briefing below lists in-stock medicines, or the page
         would contradict itself. --}}
    @unless ($hasBriefingProducts)
        <p class="small text-gray-600 text-center mt-4 mb-0">
            <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>{{ __('messages.health.no_products') }}
        </p>
    @endunless
@endif

{{-- Side channel, deliberately last and deliberately opt-in: the answer above is
     the result, a pharmacist is brought in only when the customer asks. Hidden for
     emergency, where the only action is calling 115. --}}
@if ($outcome !== 'emergency')
    @if ($session->pharmacist_note)
        {{-- Already answered by a human; the note is rendered above. --}}
    @elseif ($session->status === 'pending_pharmacist')
        <p class="small text-gray-600 text-center mt-4 mb-0 health-no-print">
            <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>{{ __('messages.health.pharmacist_request_pending') }}
        </p>
    @elseif ($session->status === 'completed')
        <div class="health-card rounded-4 border-0 p-4 mt-4 health-no-print">
            <h3 class="h6 fw-semibold text-gray-800 mb-2">
                <i class="bi bi-person-badge me-2" aria-hidden="true"></i>{{ __('messages.health.pharmacist_request_title') }}
            </h3>
            <p class="small text-gray-600 mb-3">{{ __('messages.health.pharmacist_request_hint') }}</p>

            <form method="POST" action="{{ route('health.requestPharmacist', $session->MaSL) }}">
                @csrf
                <button type="submit" class="health-btn health-btn-ghost">
                    {{ __('messages.health.pharmacist_request_btn') }}
                    <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    @endif
@endif
