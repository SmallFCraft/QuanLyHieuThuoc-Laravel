@php
    // Both blocks are open by default. They were collapsed when they were the tail of
    // a long column and collapsing them saved a screen; inside their own tab they ARE
    // the content, and a tab holding two closed chevrons shows nothing at all.
    //
    // Every value here is already persisted on the session — nothing is re-derived and
    // no medical content is invented. It is the audit trail behind a decision the
    // reader has already seen.
@endphp

<div class="health-card rounded-4 border-0 p-4">
    <h2 class="h6 fw-semibold text-gray-800 mb-3">
        <i class="bi bi-list-check me-1" aria-hidden="true"></i>{{ __('messages.health.basis_title') }}
    </h2>

    <dl class="health-basis-list mb-3">
        @if (!empty($basis['symptoms']))
            <div>
                <dt>{{ __('messages.health.basis_symptoms') }}</dt>
                <dd>{{ implode(', ', $basis['symptoms']) }}</dd>
            </div>
        @endif

        @if ($basis['duration_days'] !== null)
            <div>
                <dt>{{ __('messages.health.basis_duration') }}</dt>
                <dd>
                    {{ (int) $basis['duration_days'] <= 1
                        ? __('messages.health.basis_duration_today')
                        : __('messages.health.basis_duration_days', ['days' => (int) $basis['duration_days']]) }}
                </dd>
            </div>
        @endif

        @if ($basis['age'] !== null)
            <div>
                <dt>{{ __('messages.health.basis_age') }}</dt>
                <dd>{{ __('messages.health.basis_age_years', ['age' => (int) $basis['age']]) }}</dd>
            </div>
        @endif

        @if ($basis['clarify_rounds'] > 0)
            <div>
                <dt>{{ __('messages.health.basis_clarify') }}</dt>
                <dd>{{ __('messages.health.basis_clarify_rounds', ['count' => $basis['clarify_rounds']]) }}</dd>
            </div>
        @endif
    </dl>

    {{-- "No red flag found" is a result, not an empty state: it is the reason
         self-care was allowed at all. --}}
    @if (!empty($basis['red_flags']))
        <p class="small fw-medium text-gray-700 mb-2">{{ __('messages.health.basis_red_flags_found') }}</p>
        <ul class="list-unstyled d-flex flex-wrap gap-2 mb-3">
            @foreach ($basis['red_flags'] as $flag)
                <li class="badge bg-white text-gray-800 border px-3 py-2 rounded-pill fw-normal">
                    <i class="bi bi-dot" aria-hidden="true"></i>{{ $flag['name_vi'] ?? '' }}
                </li>
            @endforeach
        </ul>
    @else
        <p class="small text-gray-700 mb-3">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ __('messages.health.basis_red_flags_cleared') }}
        </p>
    @endif

    <p class="small text-gray-600 mb-0">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('messages.health.basis_note') }}
    </p>
</div>

{{-- What the user told us, so the printout stands alone at the clinic --}}
@if (!empty($session->collected_data))
    <div class="health-card rounded-4 border-0 p-4 mt-4">
        <h2 class="h6 fw-semibold text-gray-800 mb-3">
            <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>{{ __('messages.health.answers_title') }}
        </h2>

        <dl class="health-answer-list row g-3 mb-0">
            @php
                $data = $session->collected_data;
                $rows = [
                    __('messages.health.step_1') => $data['raw_symptom_text'] ?? ($data['symptoms'] ?? null),
                    __('messages.health.step_2') => isset($data['duration_days']) && $data['duration_days'] !== null
                        ? (int) $data['duration_days'] <= 1
                            ? __('messages.health.basis_duration_today')
                            : __('messages.health.basis_duration_days', ['days' => (int) $data['duration_days']])
                        : null,
                    __('messages.health.step_3') => $data['medications_taken'] ?? null,
                    __('messages.health.step_4') => $data['history'] ?? null,
                    __('messages.health.step_5') => $data['other_symptoms'] ?? null,
                ];
            @endphp

            @foreach ($rows as $label => $value)
                @if (!empty($value))
                    <div class="col-12 col-sm-6">
                        <dt>{{ $label }}</dt>
                        <dd class="mb-0">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>

        {{-- Follow-up rounds belong on the printout too — they are often the answers
             that decided the outcome — but kept in their own group so they are not
             mistaken for the five wizard steps. --}}
        @if ($session->clarifyHistory() !== [])
            <h3 class="small fw-semibold text-gray-700 mt-4 mb-2">
                <i class="bi bi-robot me-1" aria-hidden="true"></i>{{ __('messages.health.answers_clarify_title') }}
            </h3>
            <dl class="health-answer-list row g-3 mb-0">
                @foreach ($session->clarifyHistory() as $pair)
                    <div class="col-12 col-sm-6">
                        <dt>{{ $pair['question'] ?: __('messages.health.clarify_title') }}</dt>
                        <dd class="mb-0">{{ $pair['answer'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
@endif
