/**
 * Triage wizard: swap the question in place instead of reloading the page.
 *
 * Only the stage region changes — header, disclaimer and footer links stay put, so a
 * step change reads as a step change rather than a page load. The server returns the
 * rendered stage HTML rather than a JSON model, because rebuilding the questions and
 * the follow-up copy here would duplicate every Vietnamese medical string into the
 * client.
 *
 * Progressive enhancement: every form still POSTs and redirects with JavaScript off.
 * The result page is a real navigation, never a swap.
 */

const STAGE = '[data-triage-stage]';
const POLL_FIRST_DELAY = 900;
const POLL_DELAYS = [900, 1200, 1600, 2000, 2400];
const POLL_MAX_ATTEMPTS = 60;
/** Long enough that the customer sees the deciding state settle before it changes. */
const SLOW_NOTICE_AFTER = 12;

function stage() {
    return document.querySelector(STAGE);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function alerts() {
    return document.querySelector('[data-triage-alerts]');
}

function clearAlerts() {
    const region = alerts();

    if (region) {
        region.innerHTML = '';
    }
}

/**
 * Errors arrive without a page load, so they have to be announced and focused —
 * otherwise a screen-reader user submits and hears nothing.
 */
function showAlert(message, tone = 'danger') {
    const region = alerts();

    if (!region) {
        window.alert(message);

        return;
    }

    region.innerHTML = '';

    const box = document.createElement('div');
    box.className = `alert alert-${tone} rounded-4 mt-3 mb-0`;
    box.setAttribute('role', 'alert');
    box.setAttribute('tabindex', '-1');
    box.textContent = message;

    region.appendChild(box);
    box.focus({ preventScroll: true });
}

/**
 * Replace the stage and move focus to its heading.
 *
 * preventScroll matters: focusing without it scrolls the question to the top and
 * pushes the scope notice out of view, and that notice has no dismiss control precisely
 * so it stays readable. The once-a-day popup is a separate fixed element and is
 * unaffected either way.
 */
function swapStage(html, direction = 'forward') {
    const host = stage();

    if (!host) {
        return;
    }

    host.setAttribute('data-direction', direction);
    host.innerHTML = html;

    host.querySelector('#triage-question')?.focus({ preventScroll: true });
    startPolling();
}

/**
 * Put the form into its waiting state.
 *
 * `busy` alone used to change opacity and nothing else, so a customer who pressed
 * "Gửi và tiếp tục" got no signal at all that anything was happening — the spinning
 * they saw was the browser's own progress cursor, which reads as "the machine is
 * slow", not "your answer is being read".
 *
 * `pressed` is the button that was actually clicked. Every submit button is disabled
 * (a second click must not post twice), but only that one spins and swaps its label:
 * marking them all busy would both lie to a screen reader and turn the whole form
 * into a row of identical "Đang xử lý…" labels.
 *
 * Back and Skip have no label element — they are short enough that the spinner alone
 * reads as acknowledgement, and swapping "Quay lại" for "Đang xử lý…" would suggest
 * the wizard is moving forward when it is moving back.
 */
function setBusy(form, busy, pressed = null) {
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = busy;

        const active = busy && (pressed === null || button === pressed);

        if (active) {
            button.setAttribute('aria-busy', 'true');
        } else {
            button.removeAttribute('aria-busy');
        }

        const label = button.querySelector('.health-btn__label');

        if (!label) {
            return;
        }

        label.textContent = active
            ? button.dataset.busyLabel ?? label.textContent
            : label.dataset.idleLabel ?? label.textContent;
    });
}

async function post(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body,
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));

    return { response, data };
}

let pollTimer = null;
let pollAttempt = 0;

function stopPolling() {
    if (pollTimer !== null) {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    }

    pollAttempt = 0;
}

/**
 * Watch a queued decision. The provider call takes 11-30s, so backoff keeps the
 * polling cheap without making the wait feel unattended.
 */
function startPolling() {
    stopPolling();

    const panel = stage()?.querySelector('[data-triage-deciding]');
    const url = panel?.dataset.pollUrl;

    if (!url) {
        return;
    }

    const status = panel.querySelector('[data-triage-deciding-status]');
    const slowNotice = panel.dataset.slowNotice;

    const tick = async () => {
        pollAttempt += 1;

        if (pollAttempt > POLL_MAX_ATTEMPTS) {
            window.location.assign(window.location.pathname);

            return;
        }

        if (pollAttempt === SLOW_NOTICE_AFTER && status && slowNotice) {
            status.textContent = slowNotice;
        }

        let payload;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            // 404/410: the job is gone. Reloading re-decides rather than spinning.
            if (!response.ok && response.status !== 202) {
                window.location.assign(window.location.pathname);

                return;
            }

            payload = await response.json();
        } catch (error) {
            // A dropped connection is not a decision; try again.
            schedule();

            return;
        }

        if (payload.status === 'done' && payload.next === 'result') {
            window.location.assign(payload.redirect_url);

            return;
        }

        // A new follow-up round, a failed job, or a worker that never started: the
        // wizard re-renders, and the server decides in that request.
        if (payload.status === 'done' || payload.status === 'failed' || payload.stranded) {
            window.location.assign(payload.redirect_url ?? window.location.pathname);

            return;
        }

        schedule();
    };

    const schedule = () => {
        const delay = POLL_DELAYS[Math.min(pollAttempt, POLL_DELAYS.length - 1)];
        pollTimer = window.setTimeout(tick, delay);
    };

    pollTimer = window.setTimeout(tick, POLL_FIRST_DELAY);
}

document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('[data-triage-form]');

    if (!form || form.tagName !== 'FORM') {
        return;
    }

    event.preventDefault();
    clearAlerts();
    stopPolling();

    // Built BEFORE disabling the buttons: a disabled control is excluded from
    // FormData, so disabling first drops the clicked button's name=value — which is
    // how a quick-pick option and the Skip button carry their answer.
    const body = new FormData(form, event.submitter ?? undefined);
    const direction = form.dataset.triageDirection === 'back' ? 'back' : 'forward';

    setBusy(form, true, event.submitter ?? null);

    try {
        const { response, data } = await post(form.action, body);

        if (data.mode === 'result' && data.redirect_url) {
            window.location.assign(data.redirect_url);

            return;
        }

        if (typeof data.stage === 'string' && data.stage !== '') {
            swapStage(data.stage, direction);

            return;
        }

        // 409 stale step / 410 expired session: nothing to swap, so reload.
        if (data.redirect_url) {
            window.location.assign(data.redirect_url);

            return;
        }

        if (data.error) {
            showAlert(data.error, response.status === 422 ? 'danger' : 'warning');

            return;
        }

        window.location.reload();
    } catch (error) {
        // Offline or the server never answered: the form still works without JS, so
        // say so rather than silently doing nothing.
        showAlert('Dạ, em đang không kết nối được. Anh/chị kiểm tra mạng rồi thử lại giúp em nhé.', 'warning');
    } finally {
        setBusy(form, false);
    }
});

// A queued decision may already be in flight on first paint (the page was reloaded
// while a worker was deciding).
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPolling);
} else {
    startPolling();
}
