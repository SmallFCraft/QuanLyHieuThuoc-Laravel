{{-- The one region that changes between steps: a question, a follow-up round, the
     deciding state, or the start card.
     Rendered both by the full page and by the AJAX response, so the markup has a
     single source of truth and no Vietnamese string or medical label is duplicated
     into JavaScript. --}}

@if ($pendingJobId)
    @include('health.partials.deciding', [
        'pendingJobId' => $pendingJobId,
        'answeredSteps' => $answeredSteps,
    ])
@elseif (!empty($clarifyQuestions))
    {{-- Bounded AI follow-up rounds. Deliberately NOT extra wizard steps:
         TOTAL_STEPS stays 5 and the progress bar keeps its meaning.
         Plain form POST, so this works with JavaScript off like the rest. --}}
    <div class="health-card p-4 p-md-5">
        <div class="d-flex gap-3 align-items-start mb-3">
            <span class="health-icon-tile health-icon-tile--accent" aria-hidden="true">
                <i class="bi bi-chat-square-dots"></i>
            </span>
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <h2 class="mb-0" id="triage-question" tabindex="-1">{{ __('messages.health.clarify_title') }}</h2>
                    <span class="health-round-badge">
                        {{ __('messages.health.clarify_round', ['round' => $clarifyRound, 'total' => $clarifyMaxRounds]) }}
                    </span>
                </div>
                <p class="small mb-0" style="color: var(--health-ink-soft);">{{ __('messages.health.clarify_hint') }}</p>
            </div>
        </div>

        @include('health.partials.trail', ['answeredSteps' => $answeredSteps])

        <form method="POST" action="{{ route('health.clarify') }}" data-triage-form>
            @csrf
            <input type="hidden" name="triage_session_id" value="{{ $session->MaSL }}">

            {{-- Unnamed default button first, same reason as the step form: Enter must
                 not activate the Skip button and throw the answers away. --}}
            <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">
                {{ __('messages.health.clarify_submit') }}
            </button>

            @foreach ($clarifyQuestions as $index => $clarifyQuestion)
                <div class="health-field">
                    <label for="clarify-{{ $index }}" class="health-field__label">
                        <span class="health-field__num" aria-hidden="true">{{ $index + 1 }}</span>
                        <span>{{ $clarifyQuestion }}</span>
                    </label>
                    <input type="text"
                           class="health-input"
                           id="clarify-{{ $index }}"
                           name="clarify[{{ $index }}]"
                           maxlength="500"
                           autocomplete="off"
                           aria-label="{{ __('messages.health.clarify_answer_label', ['question' => $clarifyQuestion]) }}">
                </div>
            @endforeach

            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
                <button type="submit" name="skipped" value="1" class="health-btn health-btn-ghost">
                    <span class="health-btn__spinner" aria-hidden="true"></span>
                    {{ __('messages.health.clarify_skip') }}
                </button>
                <button type="submit"
                        class="health-btn health-btn-primary"
                        data-busy-label="{{ __('messages.health.btn_working') }}">
                    <span class="health-btn__spinner" aria-hidden="true"></span>
                    <span class="health-btn__label" data-idle-label="{{ __('messages.health.clarify_submit') }}">
                        {{ __('messages.health.clarify_submit') }}
                    </span>
                    <i class="bi bi-arrow-right health-btn__arrow" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    </div>
@elseif ($question)
    <div class="health-card p-4 p-md-5">
        @include('health.partials.progress', ['question' => $question])

        @if ($hasProfile && $question['step'] === 1)
            <p class="small d-flex align-items-start gap-2 mb-3" style="color: var(--health-selfcare);">
                <i class="bi bi-shield-check flex-shrink-0" aria-hidden="true" style="margin-top: 2px;"></i>
                <span>{{ __('messages.health.profile_hint') }}</span>
            </p>
        @endif

        @include('health.partials.trail', ['answeredSteps' => $answeredSteps])

        <h2 class="mb-4" id="triage-question" tabindex="-1">
            {{ $question['text'] }}
        </h2>

        {{-- Quick-pick buttons: each submits the form immediately.
             Plain form POST, so the wizard works with JavaScript off. --}}
        <form method="POST" action="{{ route('health.answer') }}" id="triage-form" data-triage-form>
            @csrf
            <input type="hidden" name="triage_session_id" value="{{ $session->MaSL }}">
            <input type="hidden" name="step" value="{{ $question['step'] }}">

            {{-- The form's default button, and deliberately the FIRST submit button in
                 tree order. HTML implicit submission activates the first submit button,
                 so without this one Enter in the text box activated the first quick-pick
                 option and posted `option_answer=<first option>` alongside the typed
                 `answer` — the patient typed one complaint and the wizard recorded
                 another. It carries no name, so Enter posts only what was typed.
                 Inert to assistive tech: the visible "Tiếp tục" button does the same job. --}}
            <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">
                {{ __('messages.health.btn_next') }}
            </button>

            @if (!empty($question['options']))
                <div class="health-options mb-4">
                    @foreach ($question['options'] as $option)
                        {{-- Distinct field name: sharing `answer` with the text input below
                             means PHP's last-wins parse discards the clicked option.
                             formnovalidate so step 1's required text input does not block it. --}}
                        <button type="submit" name="option_answer" value="{{ $option }}"
                                formnovalidate class="health-option">
                            {{ $option }}
                        </button>
                    @endforeach
                </div>

                <div class="d-flex align-items-center gap-3 mb-3" aria-hidden="true">
                    <span class="flex-grow-1" style="height: 1px; background: #E5E7EB;"></span>
                    <span class="small text-gray-500">{{ __('messages.health.or_type') }}</span>
                    <span class="flex-grow-1" style="height: 1px; background: #E5E7EB;"></span>
                </div>
            @endif

            {{-- Real label, not a placeholder standing in for one --}}
            <label for="answer" class="health-field__label mb-2">
                {{ __('messages.health.answer_label') }}
            </label>
            <input type="text"
                   class="health-input"
                   id="answer"
                   name="answer"
                   value="{{ old('answer', $previousAnswer ?? '') }}"
                   maxlength="1000"
                   placeholder="{{ $question['input_hint'] ?? '' }}"
                   @if (!empty($question['input_hint'])) aria-describedby="answer-hint" @endif
                   @if ($question['step'] === 1) required @endif
                   autocomplete="off">

            {{-- The example was placeholder-only, so it vanished on the first keystroke
                 exactly when it was still useful. --}}
            @if (!empty($question['input_hint']))
                <p class="small mt-2 mb-0" id="answer-hint" style="color: var(--health-ink-mute);">{{ $question['input_hint'] }}</p>
            @endif

            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-between align-items-stretch align-items-sm-center mt-4">
                <div class="d-flex gap-2">
                    @if ($question['step'] > 1)
                        {{-- Back is available at every step past the first (escape route) --}}
                        <button type="submit"
                                class="health-btn health-btn-ghost"
                                form="triage-back-form">
                            <span class="health-btn__spinner" aria-hidden="true"></span>
                            <i class="bi bi-arrow-left health-btn__arrow" aria-hidden="true"></i>{{ __('messages.health.btn_back') }}
                        </button>
                    @endif
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2">
                    @if (!empty($question['allow_skip']))
                        <button type="submit" name="skipped" value="1" class="health-btn health-btn-ghost">
                            <span class="health-btn__spinner" aria-hidden="true"></span>
                            {{ __('messages.health.btn_skip') }}
                        </button>
                    @endif

                    {{-- Two labels, one shown: the busy text is in the DOM from the
                         start so swapping it costs no layout shift, and `data-label`
                         carries the idle one for setBusy() to restore. --}}
                    <button type="submit"
                            class="health-btn health-btn-primary"
                            data-busy-label="{{ __('messages.health.btn_working') }}">
                        <span class="health-btn__spinner" aria-hidden="true"></span>
                        <span class="health-btn__label" data-idle-label="{{ __('messages.health.btn_next') }}">
                            {{ __('messages.health.btn_next') }}
                        </span>
                        <i class="bi bi-arrow-right health-btn__arrow" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            @if (!empty($question['skip_warning']))
                <p class="small text-gray-500 mt-3 mb-0">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ $question['skip_warning'] }}
                </p>
            @endif
        </form>
    </div>

    {{-- Separate form: nesting forms is invalid HTML, so Back lives outside --}}
    <form method="POST" action="{{ route('health.back') }}" id="triage-back-form" class="d-none" data-triage-form data-triage-direction="back">
        @csrf
        <input type="hidden" name="triage_session_id" value="{{ $session->MaSL }}">
    </form>
@else
    {{-- Reachable when the profile answered everything: send them straight to evaluation --}}
    <div class="health-card p-4 p-md-5 text-center">
        <h2 class="h5 fw-bold text-gray-900 mb-2" id="triage-question" tabindex="-1">
            {{ __('messages.health.page_title') }}
        </h2>
        <p class="text-gray-700 mb-4">{{ __('messages.health.page_subtitle') }}</p>
        <form method="POST" action="{{ route('health.answer') }}" data-triage-form>
            @csrf
            <input type="hidden" name="triage_session_id" value="{{ $session->MaSL }}">
            <input type="hidden" name="step" value="{{ \App\Models\TriageSession::TOTAL_STEPS }}">
            <input type="hidden" name="skipped" value="1">
            <button type="submit"
                    class="health-btn health-btn-primary"
                    data-busy-label="{{ __('messages.health.deciding_title') }}">
                <span class="health-btn__spinner" aria-hidden="true"></span>
                <span class="health-btn__label" data-idle-label="{{ __('messages.health.btn_start') }}">
                    {{ __('messages.health.btn_start') }}
                </span>
            </button>
        </form>
    </div>
@endif
