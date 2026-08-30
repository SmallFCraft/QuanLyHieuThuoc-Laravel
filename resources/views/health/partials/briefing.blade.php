@php
    // Section order is fixed and meaningful: why first (it justifies the conclusion
    // the customer just read), then what they can do, then what to watch, then when
    // to escalate, then what to ask a human. Escalation before the shopping list.
    $sections = [
        'possible_causes' => ['icon' => 'bi-diagram-3', 'label' => __('messages.health.briefing_possible_causes')],
        'self_care' => ['icon' => 'bi-house-heart', 'label' => __('messages.health.briefing_self_care')],
        'watch_for' => ['icon' => 'bi-eye', 'label' => __('messages.health.briefing_watch_for')],
        'see_doctor_when' => ['icon' => 'bi-hospital', 'label' => __('messages.health.briefing_see_doctor_when')],
        'ask_pharmacist' => ['icon' => 'bi-chat-square-quote', 'label' => __('messages.health.briefing_ask_pharmacist')],
    ];

    $why = trim((string) ($briefing['why'] ?? ''));
    $filled = collect($sections)->filter(fn ($meta, $key) => !empty($briefing[$key]));
    $products = collect($briefing['products'] ?? []);
    $flaggedProducts = array_map('intval', $flaggedProducts ?? []);

    // Jump links. Six sections of prose is several screens on a phone, and the whole
    // point of this page is that somebody anxious can find the part they need.
    $toc = $filled->map(fn ($meta, $key) => ['id' => 'briefing-' . str_replace('_', '-', $key)] + $meta);

    if ($products->isNotEmpty()) {
        $toc = $toc->put('products', [
            'id' => 'briefing-products',
            'icon' => 'bi-capsule',
            'label' => __('messages.health.briefing_products_title'),
        ]);
    }
@endphp

<section class="health-brief mt-4" aria-labelledby="briefing-heading">
    <div class="health-brief__head">
        <span class="health-icon-tile health-icon-tile--accent" aria-hidden="true">
            <i class="bi bi-stars"></i>
        </span>
        <div>
            <h2 class="mb-1" id="briefing-heading">{{ __('messages.health.briefing_title') }}</h2>
            {{-- One AI label for the whole block, at the top: repeating a badge per
                 section turns the provenance notice into wallpaper. --}}
            <p class="health-ai-badge small mb-1">
                <i class="bi bi-robot" aria-hidden="true"></i>
                <span>{{ __('messages.health.ai_badge') }}</span>
            </p>
            <p class="small mb-0" style="color: var(--health-ink-soft);">{{ __('messages.health.briefing_notice') }}</p>
        </div>
    </div>

    @if ($why !== '')
        <div class="health-brief__why">
            <h3 class="health-brief__section-title">
                <i class="bi bi-lightbulb" aria-hidden="true"></i>{{ __('messages.health.briefing_why') }}
            </h3>
            <p class="mb-0" style="white-space: pre-line; line-height: 1.75;">{{ $why }}</p>
        </div>
    @endif

    {{-- Jump links, not a sticky sidebar: the shell is 768px and a second column
         would halve the reading measure on the one page that is all prose. --}}
    @if ($toc->count() > 2)
        <nav class="health-brief__toc health-no-print" aria-label="{{ __('messages.health.briefing_toc') }}">
            <p class="health-brief__toc-title">{{ __('messages.health.briefing_toc') }}</p>
            <ul class="health-brief__toc-list">
                @foreach ($toc as $item)
                    <li>
                        <a href="#{{ $item['id'] }}" class="health-brief__toc-link">
                            <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>{{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    @foreach ($filled as $key => $meta)
        <div class="health-brief__section" id="briefing-{{ str_replace('_', '-', $key) }}">
            <h3 class="health-brief__section-title">
                <i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i>{{ $meta['label'] }}
            </h3>
            <ul class="health-brief__list">
                @foreach ($briefing[$key] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @if ($products->isNotEmpty())
        <div class="health-brief__section" id="briefing-products">
            <h3 class="health-brief__section-title">
                <i class="bi bi-capsule" aria-hidden="true"></i>{{ __('messages.health.briefing_products_title') }}
            </h3>
            <p class="small mb-3" style="color: var(--health-ink-soft);">{{ __('messages.health.briefing_products_note') }}</p>

            <div class="row g-3">
                @foreach ($products as $product)
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

                            @if (!empty($product['reason']))
                                <p class="small text-gray-700 mb-2">
                                    <span class="text-gray-600">{{ __('messages.health.briefing_product_reason') }}:</span>
                                    {{ $product['reason'] }}
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
    @endif
</section>
