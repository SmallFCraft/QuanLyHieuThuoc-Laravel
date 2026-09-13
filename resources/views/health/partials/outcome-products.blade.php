@php
    // The rule engine's suggested medicines, lifted out of outcome.blade.php so the
    // conclusion stays whole and this list can travel to its own tab. Reads the same
    // session snapshot: only the image and stock are re-read live, because what the
    // customer was shown must not silently change after the fact.
    $flaggedProducts = array_map('intval', $safety['flagged'] ?? []);
    // The AI briefing lists its own in-stock medicines, which changes what "no
    // medicine matches" means here — rendering both would contradict the page.
    $hasBriefingProducts = !empty(data_get($briefing ?? null, 'products'));
@endphp

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
                                <p class="small text-gray-600 mb-3">
                                    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                                    {{ __('messages.health.product_stock_in') }}
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
