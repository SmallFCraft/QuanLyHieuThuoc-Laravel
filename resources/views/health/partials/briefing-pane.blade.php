@if ($briefing !== null)
    @include('health.partials.briefing', ['briefing' => $briefing])
@elseif ($pending)
    @include('health.partials.briefing-pending')
    @include('health.partials.briefing-unavailable', ['hidden' => true])
@elseif ($unavailable)
    @include('health.partials.briefing-unavailable')
@endif
