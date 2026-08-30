{{-- Severity scale for the four triage outcomes.
     Every level pairs colour + icon + text label so meaning never depends on
     colour alone (WCAG: don't convey info by colour only).
     Tones chosen to clear 4.5:1 against their own background. --}}

{{-- Lexend for headings on the triage pages only. It is not decoration: Lexend was
     designed and tested to improve reading proficiency, and this is the one place in
     the shop where an anxious person has to read carefully and get it right. Same
     provider as the site's Inter, so no new origin. --}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=lexend:500,600,700,800&display=swap" rel="stylesheet">

<style>
    .health-page {
        /* #DC2626 measured only 4.41:1 on #FEF2F2 — below the 4.5 threshold — so the
           emergency tone is one step darker. White on #B91C1C measures 6.47:1. */
        --health-emergency: #B91C1C;
        --health-emergency-hover: #991B1B;
        --health-emergency-bg: #FEF2F2;
        --health-emergency-border: #FECACA;

        --health-refer: #B45309;
        --health-refer-bg: #FFFBEB;
        --health-refer-border: #FDE68A;

        --health-pharmacist: #0E7490;
        --health-pharmacist-bg: #ECFEFF;
        --health-pharmacist-border: #A5F3FC;

        --health-selfcare: #047857;
        --health-selfcare-bg: #ECFDF5;
        --health-selfcare-border: #A7F3D0;

        /* rgba(16,185,129,.35) composited to 1.40:1 — the only focus indicator on the
           page was effectively invisible. #065F46 measures 7.68:1. */
        --health-focus-ring: 0 0 0 3px #065F46;

        /* Local scale: the site has no radius/space/type tokens, and .health-page is
           already the established scope for --health-* (pharmacist/layout.blade.php
           consumes the severity vars from here). */
        --health-r-sm: 10px;
        --health-r-md: 14px;
        --health-r-lg: 20px;
        --health-r-pill: 999px;

        --health-ink: #0f172a;
        --health-ink-soft: #475569;
        --health-ink-mute: #64748b;
        --health-line: #6B7280;

        /* Matches --pharma-* elsewhere on the site so these pages are continuous with
           the storefront rather than a separate island. */
        --health-accent: #0369a1;
        --health-shadow: 0 18px 44px rgba(20, 83, 45, 0.10);
        --health-shadow-hover: 0 22px 54px rgba(20, 83, 45, 0.16);
        --health-motion: 220ms cubic-bezier(.2, .8, .2, 1);

        --health-display: 'Lexend', 'Inter', system-ui, sans-serif;
    }

    /* Headings only. Body text stays Inter so the pages still read as this shop. */
    .health-page h1,
    .health-page h2,
    .health-page .health-outcome__title,
    .health-page .health-display {
        font-family: var(--health-display);
        letter-spacing: -0.015em;
    }

    /* One fluid step instead of Bootstrap's fixed h2/h5: on a 375px screen the old
       h2 wrapped to three lines and pushed the disclaimer off-screen. */
    .health-page h1 {
        font-size: clamp(1.5rem, 1.15rem + 1.6vw, 2rem);
        font-weight: 800;
        line-height: 1.2;
    }

    .health-page .health-card h2,
    .health-page .health-outcome h2 {
        font-size: clamp(1.0625rem, 1rem + 0.6vw, 1.375rem);
        font-weight: 700;
        line-height: 1.35;
    }

    /* Global a:focus is rgba(16,185,129,.5) at 1.63:1 (theme.css); the history cards
       are whole-card anchors, so it has to be overridden inside this page. */
    .health-page a:focus-visible {
        outline: 3px solid #065F46;
        outline-offset: 2px;
    }

    .health-shell {
        max-width: 768px;
        margin-inline: auto;
    }

    /* The page tint was an inline gradient on two templates. Here it can also carry a
       soft radial wash, which is what stops the shell reading as a form on a white
       sheet without adding any element to the page. */
    .health-page {
        background:
            radial-gradient(1100px 420px at 50% -8%, rgba(21, 128, 61, 0.10), transparent 62%),
            linear-gradient(180deg, #F0FDF4 0%, #FFFFFF 340px);
    }

    .health-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: var(--health-r-pill);
        background: #fff;
        border: 1px solid var(--health-selfcare-border);
        box-shadow: var(--health-shadow);
        color: var(--health-selfcare);
        font-size: 0.8125rem;
        font-weight: 600;
    }

    .health-lede {
        max-width: 52ch;
        margin-inline: auto;
        /* 52ch keeps the line inside the 60-75 character band on desktop; without it the
           lede runs the full 768px shell and reads as a paragraph, not a subtitle. */
        color: var(--health-ink-soft);
    }

    .health-card {
        position: relative;
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(20px);
        box-shadow: var(--health-shadow);
        overflow: hidden;
    }

    /* 4px gradient hairline on the leading edge — the same motif the storefront cards
       use, so these pages read as part of the shop rather than a bolted-on tool. */
    .health-card::before,
    .health-outcome::before {
        content: '';
        position: absolute;
        inset-inline: 0;
        inset-block-start: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--health-selfcare), var(--health-accent));
    }

    .health-outcome { position: relative; overflow: hidden; }

    .health-outcome--emergency::before { background: linear-gradient(90deg, var(--health-emergency), #EF4444); }
    .health-outcome--refer_doctor::before { background: linear-gradient(90deg, var(--health-refer), #F59E0B); }
    .health-outcome--pharmacist_review::before { background: linear-gradient(90deg, var(--health-pharmacist), #22D3EE); }

    /* Card entrance, matching the storefront's `storefrontRise` idiom. Cheap and
       one-shot: the page has one card, so there is no stagger to orchestrate. */
    .health-card,
    .health-outcome { animation: healthRise 380ms cubic-bezier(.2, .8, .2, 1) both; }

    @keyframes healthRise {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: none; }
    }

    /* Scope notice: compact but never removable. Title and the way back to the full text
       are what a sighted user needs on every page view; the body is still in the
       accessibility tree and still on the printout, so a medical scope note is never
       something only a sighted, non-printing user can reach. */
    .health-disclaimer {
        background: var(--health-refer-bg);
        border: 1px solid var(--health-refer-border);
        color: #78350F;
    }

    /* Off-screen, not display:none: a screen reader must still hear the whole notice.
       Own class rather than Bootstrap's .visually-hidden so the @media print override
       below does not have to fight six !important declarations. */
    .health-disclaimer__body {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
        border: 0;
    }

    /* Once-a-day emphasis of that same text, plus whatever the "Xem lại" link reopens.

       A real modal, because the point is to be read once before the tool is used. Fixed
       overlay rather than a corner sheet: a sheet was measured covering the first
       quick-pick option at 375x812, which is worse than a modal — it blocks a control
       while looking optional. z-index clears the chatbot launcher's 2147483000, the
       highest layer in the project, so a medical notice is never painted over. */
    .health-scope-pop {
        position: fixed;
        inset: 0;
        z-index: 2147483001;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    /* 55% — inside the 40-60% convention. Isolating the panel is the whole job: the page
       behind it is a medical result, and a weak scrim leaves it competing for attention. */
    .health-scope-pop__scrim {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
    }

    .health-scope-pop__panel {
        position: relative;
        width: 100%;
        max-width: 480px;
        /* vh first: an unsupported unit inside calc() invalidates the whole declaration at
           parse time, which would leave the panel unbounded and overflow-y with nothing to
           clamp against on an engine without dvh. */
        max-height: calc(100vh - 40px);
        max-height: calc(100dvh - 40px);
        overflow-y: auto;
        padding: 28px 24px 24px;
        border-radius: var(--health-r-lg);
        background: #fff;
        border: 1px solid var(--health-refer-border);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
        text-align: center;
        animation: healthPopIn 220ms cubic-bezier(.2, .8, .2, 1) both;
    }

    /* The page-wide dark ring measures 1.9:1 against the scrim it would be painted on, so
       the panel gets a white inner ring for contrast and keeps the dark one outside it. */
    .health-scope-pop__panel:focus-visible {
        outline: none;
        box-shadow:
            0 24px 60px rgba(15, 23, 42, 0.28),
            0 0 0 3px #FFFFFF,
            0 0 0 6px #065F46;
    }

    /* box-shadow is not painted in forced-colors mode, so the ring above would vanish. */
    @media (forced-colors: active) {
        .health-scope-pop__panel:focus-visible {
            outline: 3px solid Highlight;
            outline-offset: 2px;
        }
    }

    @keyframes healthPopIn {
        from { opacity: 0; transform: translateY(12px) scale(.97); }
        to { opacity: 1; transform: none; }
    }

    /* Amber, matching the notice it repeats: this is a caution, not an error.
       Scoped with .health-page because .health-icon-tile is also a single class and sits
       later in this same <style> block, so an unscoped override would lose the cascade
       and render the self-care green. */
    .health-page .health-scope-pop__tile {
        margin-inline: auto;
        margin-bottom: 14px;
        background: var(--health-refer-bg);
        color: var(--health-refer);
    }

    .health-page .health-scope-pop__title {
        font-family: var(--health-display);
        font-size: 1.0625rem;
        font-weight: 700;
        line-height: 1.4;
        color: var(--health-ink);
        margin-bottom: 10px;
    }

    .health-scope-pop__body {
        /* #78350F on white is 9.07:1; the amber ground is reserved for the icon tile so the
           body text is not read on a tint at 0.875rem. */
        color: #78350F;
        font-size: 0.875rem;
        line-height: 1.7;
        text-align: start;
        margin-bottom: 20px;
    }

    /* --- Progress --- */
    .health-progress-track {
        height: 6px;
        background: #E5E7EB;
        border-radius: 999px;
        overflow: hidden;
    }

    .health-progress-fill {
        height: 100%;
        /* #10b981→#059669 measured 2.05/3.04:1 against the #E5E7EB track; a progress
           indicator is a control boundary and needs 3:1. */
        background: linear-gradient(90deg, #047857, #065F46);
        border-radius: 999px;
        transition: width 250ms ease-out;
    }

    /* --- Option buttons: real buttons, >=44px tall, >=8px apart --- */
    .health-options {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .health-option {
        min-height: 48px;
        padding: 12px 18px;
        border-radius: var(--health-r-md);
        /* #D1D5DB measured 1.47:1; a control boundary needs 3:1. */
        border: 1.5px solid var(--health-line);
        background: #fff;
        color: #1F2937;
        font-size: 0.9375rem;
        font-weight: 500;
        cursor: pointer;
        /* Colour/border only: scaling would shove neighbouring buttons around. */
        transition: background-color var(--health-motion), border-color var(--health-motion), color var(--health-motion);
    }

    .health-option:hover {
        background: #ECFDF5;
        border-color: #6EE7B7;
        color: #047857;
    }

    .health-option:active {
        background: #D1FAE5;
        border-color: #059669;
    }

    .health-option:focus-visible,
    .health-btn:focus-visible,
    .health-input:focus {
        outline: none;
        box-shadow: var(--health-focus-ring);
        border-color: #10b981;
    }

    .health-input {
        min-height: 48px;
        /* 16px stops iOS Safari auto-zooming the viewport on focus. */
        font-size: 1rem;
        border-radius: var(--health-r-md);
        border: 1.5px solid var(--health-line);
        padding: 12px 16px;
        width: 100%;
        transition: border-color var(--health-motion), box-shadow var(--health-motion);
    }

    /* Secondary text links under the wizard. 44px tall so they are as tappable as
       the buttons above them; #4B5563 on the page tint clears 4.5:1. */
    .health-link {
        display: inline-flex;
        align-items: center;
        min-height: 44px;
        padding: 10px 12px;
        border: 0;
        border-radius: var(--health-r-sm);
        background: transparent;
        color: #4B5563;
        text-decoration: none;
        cursor: pointer;
        transition: background-color var(--health-motion), color var(--health-motion);
    }

    .health-link:hover { background: #F3F4F6; color: #1F2937; }

    /* Colour alone measured 1.13:1 against the surrounding #6B7280, so the accent
       link also carries an underline. */
    .health-link--accent { color: var(--health-selfcare); text-decoration: underline; }
    .health-link--accent:hover { color: #065F46; }

    .health-link:focus-visible {
        outline: none;
        box-shadow: var(--health-focus-ring);
    }

    .health-btn {
        /* Without inline-flex an anchor styled as a button is ~42px tall and its
           padding overlaps the label (history.blade.php, result.blade.php). */
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 48px;
        padding: 12px 22px;
        border-radius: var(--health-r-md);
        font-weight: 600;
        font-size: 0.9375rem;
        border: 1.5px solid transparent;
        cursor: pointer;
        transition: background-color var(--health-motion), border-color var(--health-motion), color var(--health-motion), transform var(--health-motion), box-shadow var(--health-motion);
    }

    .health-btn[aria-busy="true"] {
        cursor: progress;
        opacity: 0.72;
    }

    /* White on #10b981 measures only 2.54:1. The gradient is darkened so both
       stops clear 4.5:1 for white text: #047857 = 5.48:1, #065F46 = 7.68:1. */
    .health-btn-primary {
        background: linear-gradient(135deg, #047857, #065F46);
        color: #fff;
    }

    .health-btn-primary:hover {
        background: #065F46;
        color: #fff;
        transform: translateY(-2px);
        box-shadow: var(--health-shadow-hover);
    }

    .health-btn-ghost {
        background: #fff;
        border-color: var(--health-line);
        color: #4B5563;
    }

    .health-btn-ghost:hover { background: #F9FAFB; color: #1F2937; }

    .health-btn-danger {
        background: var(--health-emergency);
        color: #fff;
        min-height: 56px;
        font-size: 1.0625rem;
    }

    .health-btn-danger:hover { background: var(--health-emergency-hover); color: #fff; }

    /* --- Outcome cards --- */
    .health-outcome { border-radius: 16px; border-width: 2px; border-style: solid; }
    .health-outcome--emergency { background: var(--health-emergency-bg); border-color: var(--health-emergency-border); }
    .health-outcome--refer_doctor { background: var(--health-refer-bg); border-color: var(--health-refer-border); }
    .health-outcome--pharmacist_review { background: var(--health-pharmacist-bg); border-color: var(--health-pharmacist-border); }
    .health-outcome--self_care { background: var(--health-selfcare-bg); border-color: var(--health-selfcare-border); }

    .health-outcome__title--emergency { color: var(--health-emergency); }
    .health-outcome__title--refer_doctor { color: var(--health-refer); }
    .health-outcome__title--pharmacist_review { color: var(--health-pharmacist); }
    .health-outcome__title--self_care { color: var(--health-selfcare); }

    .health-safety-net {
        background: var(--health-refer-bg);
        border: 1.5px solid var(--health-refer-border);
        border-radius: 12px;
        color: #78350F;
    }

    .health-confidence-track {
        height: 8px;
        background: #E5E7EB;
        border-radius: 999px;
        overflow: hidden;
    }

    .health-confidence-fill {
        height: 100%;
        background: #0E7490;
        border-radius: 999px;
    }

    .health-answer-list dt { color: var(--health-ink-mute); font-size: 0.8125rem; font-weight: 500; }
    .health-answer-list dd {
        color: #1F2937;
        font-weight: 500;
        /* Free text can be 1000 characters with no space in it; without this a single
           long token forces horizontal scroll on the whole document at 375px. */
        overflow-wrap: anywhere;
    }

    /* Decision basis: label/value rows that stay readable on one narrow column. */
    .health-basis-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px 24px;
        margin-bottom: 0;
    }

    .health-basis-list dt { color: var(--health-ink-mute); font-size: 0.8125rem; font-weight: 500; }
    .health-basis-list dd {
        color: #1F2937;
        font-weight: 600;
        margin-bottom: 0;
        overflow-wrap: anywhere;
    }

    /* --- AI briefing: the long-form answer, and the largest block on the result page.
       Given its own surface rather than another .health-card so the reader can tell
       machine-written prose from the rule engine's conclusion at a glance, without
       relying on the badge alone. --- */
    .health-brief {
        position: relative;
        border-radius: var(--health-r-lg);
        border: 1.5px solid var(--health-pharmacist-border);
        background:
            radial-gradient(120% 100% at 0% 0%, rgba(3, 105, 161, 0.06), transparent 55%),
            #fff;
        box-shadow: var(--health-shadow);
        padding: 24px;
        overflow: hidden;
        animation: healthRise 380ms cubic-bezier(.2, .8, .2, 1) both;
    }

    @media (min-width: 768px) {
        .health-brief { padding: 32px; }
    }

    .health-brief::before {
        content: '';
        position: absolute;
        inset-inline: 0;
        inset-block-start: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--health-accent), #22D3EE);
    }

    .health-brief__head {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--health-pharmacist-border);
    }

    /* Section rhythm: 24px between sections, 8px inside one. The old result page put
       everything on the same 16px gap, which is why it read as one flat column. */
    .health-brief__why,
    .health-brief__section { margin-top: 24px; }

    .health-brief__why {
        padding: 16px 18px;
        border-radius: var(--health-r-md);
        background: var(--health-pharmacist-bg);
        border: 1px solid var(--health-pharmacist-border);
    }

    .health-page .health-brief__section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        font-family: var(--health-display);
        font-size: 0.9375rem;
        font-weight: 700;
        letter-spacing: -0.005em;
        color: var(--health-ink);
    }

    .health-brief__section-title i { color: var(--health-accent); }

    /* Custom marker: a coloured dot outside the text block, so a wrapped second line
       aligns under the first word rather than under the bullet. */
    .health-brief__list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 10px;
    }

    .health-brief__list li {
        position: relative;
        padding-left: 22px;
        color: #1F2937;
        line-height: 1.7;
        overflow-wrap: anywhere;
    }

    .health-brief__list li::before {
        content: '';
        position: absolute;
        left: 6px;
        /* Centred on the first line's x-height, not the whole li: a three-line item
           would otherwise put the dot in the middle of the paragraph. */
        top: 0.65em;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--health-accent);
    }

    .health-brief--pending .health-brief__head { border-bottom: none; padding-bottom: 0; }

    /* --- Jump links. Six sections of prose is several screens on a phone, and this is
       the page where somebody worried has to find one specific part. --- */
    .health-brief__toc {
        margin-top: 20px;
        padding: 14px 16px;
        border-radius: var(--health-r-md);
        background: #F8FAFC;
        border: 1px solid #E2E8F0;
    }

    .health-brief__toc-title {
        margin: 0 0 10px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--health-ink-mute);
    }

    .health-brief__toc-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .health-brief__toc-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        /* 12px block padding + 1.45 line-height on 13px text measures 44px, the touch
           target minimum, with the 8px flex gap keeping neighbours apart. */
        padding: 12px 14px;
        border-radius: var(--health-r-pill);
        background: #fff;
        border: 1px solid var(--health-pharmacist-border);
        color: var(--health-accent);
        font-size: 0.8125rem;
        font-weight: 600;
        line-height: 1.45;
        text-decoration: none;
        transition: background var(--health-motion), border-color var(--health-motion);
    }

    .health-brief__toc-link:hover {
        background: var(--health-pharmacist-bg);
        border-color: var(--health-pharmacist);
        color: var(--health-pharmacist);
    }

    /* The fixed navbar reserves 86px, so an anchor jump would land the heading behind
       it. Applied to the section, not html, so the rest of the page is unaffected. */
    .health-brief__section[id] { scroll-margin-top: 100px; }

    /* --- Skeleton for the pending briefing. Reserves the height the real block will
       take, so nothing jumps when the poll reloads the page. --- */
    .health-skeleton {
        display: grid;
        gap: 12px;
        margin-top: 20px;
    }

    .health-skeleton__line {
        height: 12px;
        border-radius: var(--health-r-pill);
        background: linear-gradient(90deg, #EEF2F7 25%, #E2E8F0 37%, #EEF2F7 63%);
        background-size: 400% 100%;
        animation: healthShimmer 1400ms ease-in-out infinite;
    }

    .health-skeleton__line--title {
        height: 16px;
        margin-top: 8px;
    }

    @keyframes healthShimmer {
        from { background-position: 100% 50%; }
        to { background-position: 0 50%; }
    }

    /* --- Collapsible audit sections (decision basis, answers given). Closed by
       default: they are the trail behind the answer, not the answer, and leaving both
       open pushed the print button below three screens of labels. --- */
    .health-details { overflow: hidden; }

    .health-details__summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        /* 44px minimum touch target, and the whole row is the control. */
        padding: 18px 20px;
        cursor: pointer;
        list-style: none;
        font-family: var(--health-display);
        font-size: 0.9375rem;
        font-weight: 700;
        color: var(--health-ink);
        transition: background var(--health-motion);
    }

    .health-details__summary::-webkit-details-marker { display: none; }

    .health-details__summary:hover { background: rgba(3, 105, 161, 0.04); }

    .health-details__summary:focus-visible {
        outline: none;
        box-shadow: var(--health-focus-ring);
    }

    .health-details__summary-text {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .health-details__summary-text i { color: var(--health-accent); }

    .health-details__chevron {
        color: var(--health-ink-mute);
        flex-shrink: 0;
        transition: transform var(--health-motion);
    }

    .health-details[open] .health-details__chevron { transform: rotate(180deg); }

    .health-details__body {
        padding: 0 20px 20px;
        border-top: 1px solid #E5E7EB;
        padding-top: 18px;
    }

    /* --- Suggested product cards --- */
    .health-product {        /* #E5E7EB measured 1.24:1; a card boundary that carries the drug-safety flag
           has to be visible. */
        border: 1.5px solid var(--health-line);
        border-radius: var(--health-r-md);
        padding: 16px;
        background: #fff;
        overflow-wrap: anywhere;
        transition: transform var(--health-motion), box-shadow var(--health-motion);
    }

    .health-product:hover {
        transform: translateY(-3px);
        box-shadow: var(--health-shadow);
    }

    /* Border + icon + text together, so the flag survives greyscale printing and
       colour-blind viewing. */
    .health-product--flagged {
        border-color: var(--health-refer-border);
        background: var(--health-refer-bg);
    }

    .health-product__price {
        font-weight: 600;
        color: #047857;
        /* Tabular figures so prices line up between the two columns. */
        font-variant-numeric: tabular-nums;
    }

    /* Fixed aspect box: the space is reserved before the photo loads, so a slow image
       cannot push the card contents down, and the two columns stay aligned even when
       one medicine has no photo. */
    .health-product__media {
        position: relative;
        aspect-ratio: 4 / 3;
        margin-bottom: 12px;
        border: 1px solid #E5E7EB;
        border-radius: var(--health-r-sm);
        background: #F9FAFB;
        overflow: hidden;
    }

    .health-product__flag {
        display: inline-flex;
        align-items: flex-start;
        gap: 6px;
        color: #78350F;
        font-weight: 600;
        margin-bottom: 0;
    }

    /* --- <details> disclosure ---
       .health-link is inline-flex, which kills the ::marker, and text-decoration:none
       made the summary indistinguishable from body text. An explicit chevron plus an
       underline restores the affordance. */
    .health-disclose {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 44px;
        padding: 10px 12px;
        margin-inline-start: -12px;
        border-radius: var(--health-r-sm);
        color: var(--health-selfcare);
        font-weight: 500;
        text-decoration: underline;
        cursor: pointer;
        list-style: none;
    }

    .health-disclose::-webkit-details-marker { display: none; }

    .health-disclose:hover { background: #F3F4F6; }

    .health-disclose::before {
        content: '\F282';
        font-family: 'bootstrap-icons';
        font-size: 0.75rem;
        text-decoration: none;
        transition: transform var(--health-motion);
    }

    details[open] > .health-disclose::before { transform: rotate(90deg); }

    /* Section headings inside cards. h6+fw-semibold made them barely heavier than body
       text, so the result page read as one undifferentiated column. */
    .health-page .health-card h3,
    .health-page .health-outcome h3,
    .health-page .health-safety-net h3 {
        font-family: var(--health-display);
        font-size: 0.9375rem;
        font-weight: 700;
        letter-spacing: -0.005em;
        color: var(--health-ink);
    }

    /* --- Icon tile: the site's storefront idiom (40-48px box, 12-14px radius, tinted
       ground, coloured glyph), so a triage card is recognisably the same product. --- */
    .health-icon-tile {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 44px;
        height: 44px;
        border-radius: var(--health-r-md);
        font-size: 1.25rem;
        background: rgba(21, 128, 61, 0.12);
        color: var(--health-selfcare);
    }

    .health-icon-tile--accent {
        background: rgba(3, 105, 161, 0.12);
        color: var(--health-accent);
    }

    .health-round-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: var(--health-r-pill);
        background: rgba(3, 105, 161, 0.10);
        color: var(--health-accent);
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* --- Follow-up fields: numbered so three questions read as a short list rather
       than an undifferentiated stack of boxes. --- */
    .health-field + .health-field { margin-top: 20px; }

    .health-field__label {
        display: flex;
        align-items: baseline;
        gap: 10px;
        margin-bottom: 8px;
        font-weight: 500;
        color: #1F2937;
        line-height: 1.5;
    }

    .health-field__num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 22px;
        height: 22px;
        border-radius: var(--health-r-pill);
        background: rgba(3, 105, 161, 0.12);
        color: var(--health-accent);
        font-size: 0.75rem;
        font-weight: 700;
        /* Optical alignment with the first line of a wrapping question. */
        transform: translateY(2px);
    }

    /* --- Evidence trail: the answers already given, above the current question ---
       Read-only on purpose. Jumping to an arbitrary step would desynchronise
       current_step from the rendered step, and a control here would sit inside the
       disclaimer's protected region. Back is the escape route. */
    .health-trail-wrap {
        padding: 14px 16px;
        border-radius: var(--health-r-md);
        background: linear-gradient(180deg, #F0FDF4, rgba(240, 253, 244, 0));
        border: 1px solid var(--health-selfcare-border);
    }

    .health-trail__title {
        display: flex;
        align-items: center;
        gap: 6px;
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--health-selfcare);
    }

    .health-trail {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .health-trail-chip {
        display: inline-flex;
        align-items: baseline;
        gap: 6px;
        max-width: 100%;
        padding: 6px 12px;
        border-radius: var(--health-r-pill);
        background: #fff;
        border: 1px solid var(--health-selfcare-border);
        font-size: 0.8125rem;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    .health-trail-chip__label {
        color: var(--health-selfcare);
        font-weight: 600;
        flex-shrink: 0;
    }

    .health-trail-chip__value { color: var(--health-ink-soft); }

    .health-trail-chip--skipped {
        background: #F8FAFC;
        border-color: #CBD5E1;
    }

    .health-trail-chip--skipped .health-trail-chip__label { color: var(--health-ink-mute); }

    /* Newest chip arrives last and is the one that just changed. */
    .health-trail-chip--new { animation: healthChipIn 260ms cubic-bezier(.2, .8, .2, 1) both; }

    @keyframes healthChipIn {
        from { opacity: 0; transform: translateY(-4px) scale(.96); }
        to { opacity: 1; transform: none; }
    }

    /* --- Step transition. Direction encodes travel: forward enters from the right,
       Back enters from the left. --- */
    .health-stage { position: relative; }

    .health-stage[data-direction="forward"] > * { animation: healthSlideFromRight 260ms cubic-bezier(.2, .8, .2, 1) both; }
    .health-stage[data-direction="back"] > * { animation: healthSlideFromLeft 260ms cubic-bezier(.2, .8, .2, 1) both; }

    @keyframes healthSlideFromRight {
        from { opacity: 0; transform: translateX(18px); }
        to { opacity: 1; transform: none; }
    }

    @keyframes healthSlideFromLeft {
        from { opacity: 0; transform: translateX(-18px); }
        to { opacity: 1; transform: none; }
    }

    /* --- Deciding state. The provider call takes 11-30s, so this is a real screen,
       not a spinner: it says what is happening and shows the answers back. --- */
    .health-deciding__glyph { animation: healthPulse 1600ms ease-in-out infinite; }

    @keyframes healthPulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: .55; transform: scale(.9); }
    }

    .health-deciding-steps {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 10px;
    }

    .health-deciding-steps li {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.875rem;
        color: var(--health-ink-soft);
    }

    .health-deciding-steps i {
        color: var(--health-selfcare);
        /* Sequenced, not scattered: each line lights up in turn so the wait reads as
           progress rather than as three identical ticks. */
        animation: healthTick 2400ms ease-in-out infinite;
    }

    .health-deciding-steps li:nth-child(2) i { animation-delay: 320ms; }
    .health-deciding-steps li:nth-child(3) i { animation-delay: 640ms; }

    @keyframes healthTick {
        0%, 45%, 100% { opacity: .35; }
        20% { opacity: 1; }
    }

    @media (max-width: 575.98px) {
        /* Full-width so every option is one clean tap target, not a ragged wrap. */
        .health-option { width: 100%; }
    }

    @media (prefers-reduced-motion: reduce) {
        /* Everything, not a hand-listed few: any animation added later is covered. */
        .health-page *,
        .health-page *::before,
        .health-page *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }

        .health-btn-primary:hover,
        .health-product:hover { transform: none; }
    }

    /* Result page must survive Ctrl+P: users take this to an appointment. */
    /* AI provenance. Icon + text, never colour alone: the print block whitens the
       card, so the label has to survive greyscale. */
    .health-ai-badge {
        display: inline-flex;
        align-items: flex-start;
        gap: 6px;
        color: var(--health-refer);
        font-weight: 600;
    }

    @media print {
        @page { margin: 12mm; }

        /* The scope notice is compact on screen because the popup carries the full text
           there. On paper there is no popup, so the body has to come back — this is the
           printout somebody takes to a clinic. */
        .health-disclaimer__body {
            position: static;
            width: auto;
            height: auto;
            margin: 4px 0 0;
            overflow: visible;
            clip-path: none;
            white-space: normal;
        }

        /* The notice becomes two stacked blocks once the body is visible again. */
        .health-disclaimer { display: block !important; }

        .navbar,
        footer,
        .chatbot-widget-container,
        .health-no-print { display: none !important; }

        /* The gradient hairline prints as a grey band across every card. */
        .health-card::before,
        .health-outcome::before { display: none !important; }

        .health-trail-wrap {
            background: #fff !important;
            border-color: #9CA3AF !important;
        }

        /* The layout reserves 86px for the fixed navbar; on paper that is a blank
           band at the top of every page. */
        main { padding-top: 0 !important; }

        .health-page { background: #fff !important; }

        .health-card,
        .health-outcome,
        .health-brief {
            box-shadow: none !important;
            border: 1px solid #9CA3AF !important;
            background: #fff !important;
            backdrop-filter: none !important;
        }

        /* The briefing's tinted "why" panel prints as a grey slab. */
        .health-brief__why {
            background: #fff !important;
            border-color: #9CA3AF !important;
        }

        /* The chevron means nothing on paper, where every section is expanded. */
        .health-details__chevron { display: none !important; }

        /* A card split across a page break is unreadable at the clinic. */
        .health-card,
        .health-outcome,
        .health-brief__section,
        .health-product,
        .drug-warning { break-inside: avoid; }

        /* Scroll-reveal never fires without a scroll, so animated content would
           print blank. */
        .health-page *,
        .health-page *::before,
        .health-page *::after {
            animation: none !important;
            transform: none !important;
            opacity: 1 !important;
        }

        /* The 115 line is the whole point of an emergency printout; under economy
           colour it prints white-on-white. */
        .health-btn-danger {
            color: #000 !important;
            background: #fff !important;
            border: 2px solid #000 !important;
        }

        /* Flagged medicines must stay distinguishable on paper, where the tint is
           printed white. */
        .health-product--flagged {
            border-width: 2px !important;
            border-color: #374151 !important;
            background: #fff !important;
        }

        /* The pharmacist-written original must be on the printout taken to the
           clinic, not hidden behind a <details> the paper cannot open. */
        details[open] > summary,
        details > summary { list-style: none; }
        details > *:not(summary) { display: block !important; }
    }
</style>
