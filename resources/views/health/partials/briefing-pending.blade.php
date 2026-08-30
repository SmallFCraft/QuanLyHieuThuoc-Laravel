{{-- Waiting state for the detailed briefing.
     A skeleton rather than a spinner: the call takes ~30-90s, the shape of what is
     coming is known, and reserving the space means no layout jump when it lands.
     Everything above this block is already final and readable. --}}
<section class="health-brief health-brief--pending mt-4 health-no-print"
         id="triage-briefing-pending"
         aria-labelledby="briefing-pending-heading">
    <div class="health-brief__head">
        <span class="health-icon-tile health-icon-tile--accent" aria-hidden="true">
            <i class="bi bi-stars health-deciding__glyph"></i>
        </span>
        <div>
            <h2 class="mb-1" id="briefing-pending-heading">{{ __('messages.health.briefing_title') }}</h2>
            {{-- The live region is the text, not the animation: under reduced motion
                 the skeleton stops moving, so the words have to carry the state. --}}
            <p class="small mb-0" role="status" style="color: var(--health-ink-soft);">
                {{ __('messages.health.briefing_pending') }}
            </p>
        </div>
    </div>

    <div class="health-skeleton" aria-hidden="true">
        <span class="health-skeleton__line" style="width: 92%;"></span>
        <span class="health-skeleton__line" style="width: 78%;"></span>
        <span class="health-skeleton__line" style="width: 85%;"></span>
        <span class="health-skeleton__line health-skeleton__line--title" style="width: 46%;"></span>
        <span class="health-skeleton__line" style="width: 88%;"></span>
        <span class="health-skeleton__line" style="width: 66%;"></span>
    </div>
</section>
