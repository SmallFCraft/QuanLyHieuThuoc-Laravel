{{-- The answers already given, newest last.
     Read-only by design: jumping to an arbitrary step would desynchronise
     current_step from the rendered step, and any control here would sit inside the
     disclaimer's protected region. Back is the escape route. --}}
@if (!empty($answeredSteps))
    <div class="health-trail-wrap mb-4">
        <p class="health-trail__title mb-2">
            <i class="bi bi-list-check" aria-hidden="true"></i>{{ __('messages.health.trail_title') }}
        </p>

        <ul class="health-trail" data-triage-trail>
            @foreach ($answeredSteps as $index => $entry)
                <li class="health-trail-chip {{ $entry['skipped'] ? 'health-trail-chip--skipped' : '' }} {{ $index === count($answeredSteps) - 1 ? 'health-trail-chip--new' : '' }}">
                    <span class="health-trail-chip__label">{{ $entry['label'] }}</span>
                    <span class="health-trail-chip__value">
                        {{ $entry['skipped'] ? __('messages.health.trail_skipped') : $entry['answer'] }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
