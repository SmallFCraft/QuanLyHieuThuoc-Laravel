{{-- Once a calendar day, decided server-side: it renders with JavaScript off, and its
     closed state is "not rendered" rather than display:none.

     A real modal — scrim, centred panel, focus moved into it — because the point is to
     be read once before the tool is used, not to sit in the corner being ignored. What
     keeps that safe:
       * it is suppressed entirely on an emergency result, where calling 115 is the only
         action (see HealthCheckController::result);
       * dismissing is one real form POST, so the JS and no-JS paths are identical and
         the outcome page underneath is never re-rendered by it;
       * the compact notice behind it keeps the title and a "Xem lại" link forever, so
         nothing is hidden by dismissing.

     Class is not prefixed .health-disclaimer so no existing selector or test anchor
     matches it. --}}
<div class="health-scope-pop health-no-print" data-disclaimer-popup>
    {{-- Scrim first in the DOM and behind the panel: strong enough to isolate the panel
         (the 40-60% convention), and it carries no control, so a click on it is a no-op
         without JavaScript rather than a dead-looking button. --}}
    <div class="health-scope-pop__scrim" data-disclaimer-scrim></div>

    <div class="health-scope-pop__panel"
         role="dialog"
         aria-modal="true"
         aria-labelledby="health-scope-pop-title"
         aria-describedby="health-scope-pop-body"
         tabindex="-1"
         data-disclaimer-panel>
        <span class="health-icon-tile health-scope-pop__tile" aria-hidden="true">
            <i class="bi bi-info-circle-fill"></i>
        </span>

        <h2 class="health-scope-pop__title" id="health-scope-pop-title">
            {{ __('messages.health.disclaimer_title') }}
        </h2>

        <p class="health-scope-pop__body" id="health-scope-pop-body">
            {{ __('messages.health.disclaimer_body') }}
        </p>

        {{-- No data-triage-form: the wizard's delegated submit listener must not turn
             this into a stage swap. ackDisclaimer() redirects to a named route, so the
             ?disclaimer=1 of a "Xem lại" visit never survives the POST. --}}
        <form method="POST" action="{{ route('health.disclaimer.ack') }}" class="m-0">
            @csrf
            @isset($MaSL)
                <input type="hidden" name="MaSL" value="{{ $MaSL }}">
            @endisset
            <button type="submit" class="health-btn health-btn-primary w-100" data-disclaimer-ack>
                <i class="bi bi-check2" aria-hidden="true"></i>{{ __('messages.health.disclaimer_ack') }}
            </button>
        </form>
    </div>
</div>

@push('scripts')
    <script>
        // Progressive enhancement only. Without this the dialog still renders, still
        // reads, and the button still dismisses it via a full page POST; this adds the
        // things a keyboard or mouse user expects of a modal and that markup alone
        // cannot express.
        (function () {
            const root = document.querySelector('[data-disclaimer-popup]');

            if (!root) {
                return;
            }

            const panel = root.querySelector('[data-disclaimer-panel]');
            const ack = root.querySelector('[data-disclaimer-ack]');
            // Where focus goes on Escape. NOT document.activeElement, which is <body> at
            // parse time and is not focusable — focus would silently fall to the document
            // and a keyboard user would restart from the top of the page. The review link
            // is the right landing spot: it is the way back to what was just closed.
            const returnTo = document.querySelector('.health-disclaimer a');

            // preventScroll: focusing the panel without it scrolls the page behind the
            // scrim, so dismissing leaves the reader somewhere they did not choose.
            panel.focus({ preventScroll: true });

            // aria-modal is a promise to assistive tech that the rest of the page is
            // unavailable. `inert` makes it true for everyone else too.
            //
            // Walked by ancestor, not `body > *`: the popup is included inside the page
            // content, so it lives in <main>, and a body-children loop skips <main> as
            // "contains the popup" — leaving the wizard keyboard-reachable behind the
            // scrim while aria-modal claimed otherwise.
            const inerted = [];
            for (let node = root; node && node !== document.body; node = node.parentElement) {
                for (const sibling of node.parentElement.children) {
                    if (sibling !== node && !sibling.hasAttribute('inert')) {
                        sibling.setAttribute('inert', '');
                        inerted.push(sibling);
                    }
                }
            }

            // Scrolling the result underneath while a modal asks to be read first is the
            // same mixed message inert removes for the keyboard.
            const scrollLocked = document.documentElement;
            const previousOverflow = scrollLocked.style.overflow;
            scrollLocked.style.overflow = 'hidden';

            function close() {
                root.remove();
                inerted.forEach((node) => node.removeAttribute('inert'));
                scrollLocked.style.overflow = previousOverflow;

                // The daily flag is deliberately NOT set: closing without pressing the
                // button is "not now", not "understood". But the result page reloads
                // itself when a polled AI job lands, and triage.js navigates on its own
                // too — so "not now" is recorded in the URL, which those reloads keep,
                // rather than being forgotten the moment the page reloads under the
                // reader. A fresh visit has no marker and shows the dialog again.
                const url = new URL(window.location.href);
                url.searchParams.set('disclaimer', '0');
                window.history.replaceState(window.history.state, '', url);

                if (returnTo) {
                    returnTo.focus({ preventScroll: true });
                }
            }

            root.querySelector('[data-disclaimer-scrim]').addEventListener('click', close);

            document.addEventListener('keydown', function onKey(event) {
                if (event.key !== 'Escape' || !root.isConnected) {
                    return;
                }

                event.preventDefault();
                document.removeEventListener('keydown', onKey);
                close();
            });

            // One focusable control, so the trap is just "keep Tab here" rather than a
            // first/last cycle. The listener is on the panel and the button is inside it,
            // so both Tab and Shift+Tab bubble up and are caught.
            panel.addEventListener('keydown', (event) => {
                if (event.key === 'Tab') {
                    event.preventDefault();
                    ack.focus();
                }
            });
        })();
    </script>
@endpush
