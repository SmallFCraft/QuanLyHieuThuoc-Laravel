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

    // The briefing's own product picks are rendered by the shared product list in the
    // "Thuốc & chăm sóc" tab, merged with the rule engine's. Rendering them here as
    // well put the same medicine on the page twice under two different labels.

    // Jump links. Six sections of prose is several screens on a phone, and the whole
    // point of this page is that somebody anxious can find the part they need.
    $toc = $filled->map(fn ($meta, $key) => ['id' => 'briefing-' . str_replace('_', '-', $key)] + $meta);
@endphp

<section class="health-brief mt-4" aria-labelledby="briefing-heading">
    <div class="health-brief__head">
        <span class="health-icon-tile" aria-hidden="true">
            <i class="bi bi-journal-text"></i>
        </span>
        <div>
            <h2 class="mb-1" id="briefing-heading">{{ __('messages.health.briefing_title') }}</h2>
            {{-- One AI label for the whole block, at the top: repeating a badge per
                 section turns the provenance notice into wallpaper. The robot glyph is
                 gone — a mascot on a medical page invites trust the badge's plain words
                 earn; the shield-and-check alone states the caution. --}}
            <p class="health-ai-badge small mb-1">
                <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
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

</section>
