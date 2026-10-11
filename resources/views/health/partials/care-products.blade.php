@php
    // Two sources name medicines: the rule engine's `suggested_products` (deterministic
    // always) and the briefing's own `products` (AI, optional). Rendering them as two
    // lists under two labels showed the same product twice with no way to tell which
    // one the system stood behind, so they collapse here into one list keyed on id.
    //
    // The rule engine's row wins on conflict: its price and stock are the stored
    // snapshot the customer was actually shown, and only the image is re-read live.
    $hasProducts = !empty($suggestedProducts) || !empty(data_get($briefing, 'products', []));

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
