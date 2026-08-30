{{-- Shown when a briefing was due but produced nothing.

     Rendered hidden alongside the skeleton while one is still being written, so the
     poller can swap it in without a round trip. Removing the skeleton and leaving
     nothing behind reads as a page that never finished loading — this block IS the
     content, unlike the advice banner, whose text is already on the page. --}}
<p class="small text-gray-600 mt-4 mb-0 health-no-print"
   id="triage-briefing-unavailable"
   @if ($hidden ?? false) hidden @endif>
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.briefing_unavailable') }}
</p>
