@php
    // Two sources name medicines: the rule engine's `suggested_products` (deterministic
    // always) and the briefing's own `products` (AI, optional). Rendering them as two
    // lists under two labels showed the same product twice with no way to tell which
    // one the system stood behind, so they collapse here into one list keyed on id.
    //
    // The rule engine's row wins on conflict: its price and stock are the stored
    // snapshot the customer was actually shown, and only the image is re-read live.
    $rulePicks = collect($suggestedProducts ?? $session->suggested_products ?? [])
        ->filter(fn ($row) => is_array($row) && !empty($row['id']));

    $aiPicks = collect(data_get($briefing, 'products', []))
        ->filter(fn ($row) => is_array($row) && !empty($row['id']));

    $seen = $rulePicks->pluck('id')->map(fn ($id) => (int) $id)->all();

    $products = $rulePicks
        ->concat($aiPicks->reject(fn ($row) => in_array((int) $row['id'], $seen, true)))
        ->values();

    $flaggedProducts = array_map('intval', $safety['flagged'] ?? []);
    $hasProfile = $hasProfile ?? false;
    $isSelfCare = ($session->outcome ?? '') === 'self_care';
@endphp

{{-- The cart blocks a contraindicated product one click later, so the warning has to
     appear where the suggestion is made, not only at checkout. --}}
@if ($products->isNotEmpty())
    <div class="row g-3">
        @foreach ($products as $product)
            @php $isFlagged = in_array((int) ($product['id'] ?? 0), $flaggedProducts, true); @endphp
            <div class="col-12 col-sm-6">
                <div class="health-product h-100 d-flex flex-column {{ $isFlagged ? 'health-product--flagged' : '' }}">
                    @include('health.partials.product-media', ['product' => $product])

                    @if (!empty($product['category']))
                        <span class="health-product__category">{{ $product['category'] }}</span>
                    @endif

                    <p class="health-product__name">{{ $product['name'] ?? '' }}</p>

                    <p class="health-product__price mb-2">
                        <span class="health-product__price-value">
                            {{ number_format((int) ($product['price'] ?? 0), 0, ',', '.') }} ₫
                        </span>
                    </p>

                    {{-- Icon + text, never colour alone: this must survive greyscale printing. --}}
                    @if ($isFlagged)
                        <p class="health-product__flag small mb-2">
                            <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                            <span>{{ __('messages.health.product_has_warning') }}</span>
                        </p>
                    @endif

                    @if (!empty($product['reason']))
                        <p class="small text-gray-700 mb-2">
                            <span class="text-gray-600">{{ __('messages.health.briefing_product_reason') }}:</span>
                            {{ $product['reason'] }}
                        </p>
                    @endif

                    @if (!empty($product['usage']))
                        {{-- A crawled product carries up to 3,000+ characters of clinical
                             prose, which turns one card into a full screen. The dosage the
                             customer needs sits at the top, so the summary keeps that and
                             <details> holds the rest. --}}
                        @php
                            $usageText = trim($product['usage']);
                            $isLongUsage = mb_strlen($usageText) > 160;
                            $usageSummary = $isLongUsage ? \Illuminate\Support\Str::limit($usageText, 140, '...') : $usageText;
                        @endphp
                        <div class="health-product__usage mb-2">
                            <p class="small text-gray-700 mb-1">
                                <span class="fw-medium text-gray-800">{{ __('messages.health.product_usage_label') }}:</span>
                                {{ $usageSummary }}
                            </p>
                            @if ($isLongUsage)
                                <details class="health-product__usage-details">
                                    <summary class="health-product__usage-toggle" aria-label="{{ __('messages.health.product_usage_toggle_open') }}">
                                        <span class="health-product__usage-toggle-text">{{ __('messages.health.product_usage_toggle_open') }}</span>
                                        <i class="bi bi-chevron-down health-product__usage-chevron" aria-hidden="true"></i>
                                    </summary>
                                    <div class="health-product__usage-full mt-2 p-2 rounded-2 bg-slate-50 border text-gray-700 small">
                                        {{ $usageText }}
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endif

                    @php $stock = (int) ($product['stock_quantity'] ?? 0); @endphp
                    @if ($stock > 0)
                        <p class="health-product__stock mb-0">
                            <i class="bi bi-check-circle" aria-hidden="true"></i>
                            {{ __('messages.health.product_stock_in') }}
                        </p>
                    @endif

                    <div class="health-product__action">
                        {{-- Four identical "Xem chi tiết" links would give a screen
                             reader four identical accessible names. --}}
                        <a href="{{ $product['url'] ?? '#' }}"
                           class="health-btn health-btn-ghost w-100 text-center text-decoration-none health-no-print"
                           aria-label="{{ __('messages.view_details') }}: {{ $product['name'] ?? '' }}">
                            {{ __('messages.view_details') }}
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

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
        {{-- Inline, not a flex row: as a flex item this anchor blockifies, and the
             row's `align-items: center` then parked the icon at the vertical middle
             of a three-line sentence — beside no line in particular. Inline puts it
             on the first line, where the sentence it introduces starts. --}}
        <p class="small text-gray-600 mt-3 mb-0 health-no-print">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            <a href="{{ route('profile.edit') }}" class="health-link health-link--accent health-link--inline">
                {{ __('messages.health.safety_check_no_profile') }}
            </a>
        </p>
    @endif
@elseif (!$isSelfCare)
    {{-- Nothing matched the symptom, and no medicine is a decision. Saying so beats
         the old behaviour, which quietly offered the four highest-stock items. --}}
    <p class="small text-gray-600 mb-0">
        <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>{{ __('messages.health.no_products') }}
    </p>
@else
    <div class="health-card rounded-4 border-0 p-4">
        <p class="small text-gray-600 mb-0">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.products_empty') }}
        </p>
    </div>
@endif
