@if ($briefing !== null)
    @include('health.partials.analysis', ['metrics' => $metrics, 'briefing' => $briefing])
    @include('health.partials.briefing', ['briefing' => $briefing])
    {{-- Rides along in the response so the poller can update the medicines tab in the
         same tick without a second round-trip: the AI briefing picks its own products,
         which were not known when the initial page rendered. --}}
    <template id="briefing-care-products">
        @include('health.partials.care-products', [
            'session' => $session,
            'briefing' => $briefing,
            'safety' => $safety ?? [],
            'hasProfile' => $hasProfile ?? false,
            'suggestedProducts' => $suggestedProducts ?? [],
        ])
    </template>
@elseif ($pending)
    @include('health.partials.briefing-pending')
    @include('health.partials.briefing-unavailable', ['hidden' => true])
@elseif ($unavailable)
    @include('health.partials.briefing-unavailable')
@endif
