{{-- Severity scale for the four triage outcomes.
     Every level pairs colour + icon + text label so meaning never depends on
     colour alone (WCAG: don't convey info by colour only).
     Tones chosen to clear 4.5:1 against their own background. --}}

{{-- No second webfont. Lexend was pulled in here for headings, but DESIGN.md's
     Weight-Not-Family rule holds for these pages too: the triage screens are the
     ones a worried person reads, and a page that swaps faces mid-product is the
     tell that it was assembled rather than built. Inter is already loaded by the
     layout, so this file adds no origin and no request. --}}

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

        --health-display: 'Inter', system-ui, sans-serif;
    }

    /* Inter throughout, headings included. DESIGN.md's Weight-Not-Family rule: rank
       comes from weight and size, and this is the one surface where a second face
       would show as a seam. Weight runs high — a heading here is 700, a verdict 800. */
    .health-page h1,
    .health-page h2,
    .health-page .health-outcome__title,
    .health-page .health-display {
        font-family: var(--health-display);
        letter-spacing: -0.02em;
    }

    /* DESIGN.md's headline step, not a page-local clamp: the ramp is shared, and an
       h1 that only exists on two templates is how a type system stops being one. */
    .health-page h1 {
        font-size: clamp(1.35rem, 2.5vw + 0.6rem, 2rem);
        font-weight: 700;
        line-height: 1.25;
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

    /* DESIGN.md's page wash, verbatim: the horizontal mint-to-slate band the storefront
       sits on. The radial glow and the vertical fade that used to live here were a
       second lighting system on a page that already has one, and three layers of tint
       behind frosted cards read as haze rather than as depth. */
    .health-page {
        background: linear-gradient(to right, #f0fdf4 0%, #f8fafc 50%, #ecfdf5 100%);
    }

    /* --- Result masthead ---
       Left-aligned like a clinical record. The four centred lines it replaces stacked
       the section name, the page title and the conclusion, and set the conclusion —
       the one line the visitor came for — smallest of the three. */
    .health-masthead {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 16px;
    }

    .health-masthead__stamp {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8125rem;
        color: var(--health-ink-mute);
        font-variant-numeric: tabular-nums;
    }

    /* --- The verdict ---
       Clinical outcome banner: the visual anchor of the result page.
       Built on DESIGN.md's opaque warning card — solid level tint, the level's own
       border, and a soft diffused shadow. The icon is a filled medallion rather than
       a white tile: a white square on a tinted ground reads as a sticker laid over
       the card, while a solid disc in the level's colour matches the journey dots
       in the rail beside it and gives the severity a second, greyscale-safe cue. */
    .health-verdict {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-top: 18px;
        padding: 18px 22px;
        border-radius: var(--health-r-lg);
        border: 1px solid var(--health-line);
        background: #fff;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
    }

    .health-verdict__icon {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 52px;
        height: 52px;
        border-radius: var(--health-r-pill);
        font-size: 1.5rem;
        /* Same idiom as the stepper's current dot: a soft tinted ring, so the disc
           sits in the card instead of floating on it. */
        box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.55);
    }

    .health-verdict__body { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; gap: 6px; }

    /* A chip, not floating all-caps: the label belongs to the conclusion, and the
       pill says so without inventing a second colour for it. */
    .health-verdict__label {
        display: inline-flex;
        align-items: center;
        padding: 2px 10px;
        border-radius: var(--health-r-pill);
        background: rgba(255, 255, 255, 0.7);
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--health-ink-soft);
    }

    .health-verdict__value {
        font-family: var(--health-display);
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: -0.02em;
    }

    /* The border, the surface, the disc and the words all take the level's colour, so
       the card states its severity four ways and survives greyscale. Nothing here is
       blurred: DESIGN.md's Opaque Warning Rule, a warning read through glass is a
       warning half-read. White on each of the four level colours measures between
       4.8:1 and 6.5:1 — well past the 3:1 a glyph needs. */
    .health-verdict--emergency {
        border-color: var(--health-emergency);
        background: var(--health-emergency-bg);
        box-shadow: 0 12px 32px rgba(185, 28, 28, 0.08);
    }
    .health-verdict--emergency .health-verdict__icon { background: var(--health-emergency); color: #fff; }
    .health-verdict--emergency .health-verdict__value { color: var(--health-emergency); }

    .health-verdict--refer_doctor {
        border-color: var(--health-refer);
        background: var(--health-refer-bg);
        box-shadow: 0 12px 32px rgba(180, 83, 9, 0.08);
    }
    .health-verdict--refer_doctor .health-verdict__icon { background: var(--health-refer); color: #fff; }
    .health-verdict--refer_doctor .health-verdict__value { color: var(--health-refer); }

    .health-verdict--pharmacist_review {
        border-color: var(--health-pharmacist);
        background: var(--health-pharmacist-bg);
        box-shadow: 0 12px 32px rgba(14, 116, 144, 0.08);
    }
    .health-verdict--pharmacist_review .health-verdict__icon { background: var(--health-pharmacist); color: #fff; }
    .health-verdict--pharmacist_review .health-verdict__value { color: var(--health-pharmacist); }

    .health-verdict--self_care {
        border-color: var(--health-selfcare);
        background: var(--health-selfcare-bg);
        box-shadow: 0 12px 32px rgba(4, 120, 87, 0.08);
    }
    .health-verdict--self_care .health-verdict__icon { background: var(--health-selfcare); color: #fff; }
    .health-verdict--self_care .health-verdict__value { color: var(--health-selfcare); }

    /* --- Start page: the actions under the wizard ---
       A quiet row of secondary links, and below it the profile offer as its own
       surface. Flat, the three of them read as one menu and the longest label set
       the width for all of them. */
    .health-actions {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px;
    }

    .health-actions__group {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }

    /* Two links of the same weight, separated by a hairline rather than by air, so
       they read as a pair and not as two unrelated buttons. */
    .health-actions__group > * + * {
        position: relative;
        margin-inline-start: 12px;
    }

    .health-actions__group > * + *::before {
        content: '';
        position: absolute;
        inset-block: 25%;
        inset-inline-start: -8px;
        width: 1px;
        background: var(--health-selfcare-border);
    }

    /* The offer. A real surface, because it asks for something rather than just
       going somewhere: it takes the accent and the icon tile the result page uses. */
    .health-actions__offer {
        display: flex;
        align-items: center;
        gap: 12px;
        max-width: 100%;
        padding: 12px 18px 12px 12px;
        border-radius: var(--health-r-md);
        border: 1px solid var(--health-selfcare-border);
        background: linear-gradient(180deg, var(--health-selfcare-bg), rgba(236, 253, 245, 0.35));
        text-decoration: none;
        transition: border-color var(--health-motion), box-shadow var(--health-motion), transform var(--health-motion);
    }

    .health-actions__offer:hover {
        border-color: var(--health-selfcare);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        transform: translateY(-2px);
    }

    .health-actions__offer:focus-visible {
        outline: none;
        box-shadow: var(--health-focus-ring);
    }

    .health-actions__offer-tile {
        width: 38px;
        height: 38px;
        font-size: 1.0625rem;
        background: #fff;
    }

    .health-actions__offer-title {
        display: block;
        font-size: 0.9375rem;
        font-weight: 700;
        color: var(--health-selfcare);
    }

    .health-actions__offer-hint {
        display: block;
        font-size: 0.8125rem;
        line-height: 1.45;
        color: var(--health-ink-soft);
    }

    @media (max-width: 575.98px) {
        .health-actions__offer { width: 100%; text-align: start; }
    }

    .health-lede {
        /* 52ch keeps the line inside the 60-75 character band on desktop; without it the
           lede runs the full 768px shell and reads as a paragraph, not a subtitle.
           Left-aligned with the masthead above it: centring it under a left-aligned
           title put the two blocks on different axes. */
        max-width: 52ch;
        color: var(--health-ink-soft);
    }

    /* DESIGN.md's product card exactly: the surface the storefront's goods sit on, at
       its sturdier 0.94 reading for a block of form content. The 4px emerald-to-blue
       hairline that used to crown every card carried no information and printed as a
       bar — a colored rule across the top of a card is a decoration, not a boundary. */
    .health-card {
        position: relative;
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid rgba(21, 128, 61, 0.14);
        border-radius: var(--health-r-lg);
        backdrop-filter: blur(6px);
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
        overflow: hidden;
    }

    .health-outcome {
        position: relative;
        overflow: hidden;
    }

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
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: space-between;
        padding: 8px 14px;
        border: 1px solid rgba(21, 128, 61, 0.14);
        border-radius: var(--health-r-md);
        background: rgba(255, 255, 255, 0.75);
        backdrop-filter: blur(6px);
        color: var(--health-ink-soft);
        font-size: 0.875rem;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
    }

    .health-disclaimer__lead {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .health-disclaimer__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        flex-shrink: 0;
        border-radius: var(--health-r-pill);
        background: rgba(4, 120, 87, 0.08);
        color: var(--health-selfcare);
        font-size: 0.8125rem;
        line-height: 1;
    }

    .health-disclaimer__title {
        font-weight: 600;
        font-size: 0.8125rem;
        color: var(--health-ink);
        line-height: 1.35;
    }

    /* Own pill rather than .health-link: that class carries a 44px min-height for
       pointer targets, which on a one-line strip widens the notice past its own text
       and lifts it above the question it introduces. Still a full pill-sized target
       here — the strip is ~38px — and the same focus ring via :focus-visible. */
    .health-disclaimer__action {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        min-height: 28px;
        padding: 2px 10px;
        border: 1px solid rgba(4, 120, 87, 0.20);
        border-radius: var(--health-r-pill);
        background: rgba(4, 120, 87, 0.05);
        color: var(--health-selfcare);
        font-size: 0.75rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color var(--health-motion), color var(--health-motion), border-color var(--health-motion);
    }

    .health-disclaimer__action:hover {
        background: rgba(4, 120, 87, 0.12);
        border-color: rgba(4, 120, 87, 0.35);
        color: #065F46;
    }

    .health-disclaimer__action:focus-visible {
        outline: none;
        box-shadow: var(--health-focus-ring);
    }

    /* Emergency variant: popup suppressed so it never covers the 115 call button,
       so the body renders inline here with emergency tint. */
    .health-disclaimer--emergency {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        background: var(--health-emergency-bg);
        border: 1px solid var(--health-emergency-border);
        color: var(--health-emergency);
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
        /* Near-solid rather than glass: the panel floats over a 55% scrim, and a
           translucent one would pick the scrim's grey back up through it. Same
           white-plus-emerald-edge material as .health-card, kept opaque. */
        background: #fff;
        border: 1px solid rgba(21, 128, 61, 0.14);
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

    /* Emerald, matching the notice it repeats: a scope note is informational, not a
       caution. Scoped with .health-page because .health-icon-tile is also a single class
       and sits later in this same <style> block, so an unscoped override would lose the
       cascade. */
    .health-page .health-scope-pop__tile {
        margin-inline: auto;
        margin-bottom: 14px;
        background: rgba(4, 120, 87, 0.08);
        color: var(--health-selfcare);
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
        /* --health-ink-soft on white is 7.5:1. The amber ground is gone: this panel
           repeats the same scope notice the strip carries, so it wears the same
           material rather than a caution colour. */
        color: var(--health-ink-soft);
        font-size: 0.875rem;
        line-height: 1.7;
        text-align: start;
        margin-bottom: 20px;
    }

    /* --- Progress --- */
    /* Track is a control boundary, not decoration: #E2E8F0 measures 1.19:1 against
       the page, so the unfilled part carries a hairline and the filled part carries
       the emerald. No transition — the bar is re-rendered between steps, not swept
       within one, and animating width here is layout work for no visible gain. */
    .health-progress-track {
        height: 6px;
        background: #E5E7EB;
        border: 1px solid #E2E8F0;
        border-radius: 999px;
        overflow: hidden;
    }

    .health-progress-fill {
        height: 100%;
        background: var(--health-selfcare);
        border-radius: 999px;
    }

    /* --- Wizard stepper: five named nodes under the bar ---
       The bar alone says how far along; it does not say along what. The nodes name the
       five questions and mark the ones already answered, so the visitor reads the shape
       of the intake instead of counting steps in their head. Decorative for assistive
       tech (aria-hidden) — the track above carries the whole progress announcement, and
       naming the nodes twice would make a screen reader read the wizard twice. */
    .health-stepper__badge {
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        padding: 4px 12px;
        border-radius: var(--health-r-pill);
        background: var(--health-selfcare-bg);
        border: 1px solid var(--health-selfcare-border);
        color: var(--health-selfcare);
        font-size: 0.8125rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .health-stepper__nodes {
        position: relative;
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 4px;
        margin: 16px 0 0;
        padding: 0;
        list-style: none;
    }

    /* The rail between node centres. Sits behind the dots and stops at the first and
       last centre, so it reads as a path between stops rather than as an underline
       running off both ends of the row. */
    .health-stepper__nodes::before {
        content: '';
        position: absolute;
        top: 15px;
        left: 10%;
        right: 10%;
        height: 2px;
        background: linear-gradient(to right, var(--health-selfcare-border), var(--health-selfcare-border));
        z-index: 0;
    }

    .health-stepper__node {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        text-align: center;
    }

    .health-stepper__dot {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: var(--health-r-pill);
        border: 2px solid #E2E8F0;
        background: #fff;
        color: var(--health-ink-mute);
        font-size: 0.8125rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        line-height: 1;
    }

    /* Answered: filled, and marked with a tick rather than only a colour — a visitor
       who cannot separate the two greens still reads the sequence. */
    .health-stepper__node.is-done .health-stepper__dot {
        border-color: var(--health-selfcare);
        background: var(--health-selfcare);
        color: #fff;
    }

    /* Where they are now: the one node with a ring around it. The ring is the state,
       not a shadow — it survives a greyscale print and forced-colors mode. */
    .health-stepper__node.is-current .health-stepper__dot {
        border-color: var(--health-selfcare);
        background: var(--health-selfcare-bg);
        color: var(--health-selfcare);
        box-shadow: 0 0 0 4px rgba(4, 120, 87, 0.14);
    }

    .health-stepper__node-label {
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.3;
        color: var(--health-ink-mute);
    }

    .health-stepper__node.is-done .health-stepper__node-label,
    .health-stepper__node.is-current .health-stepper__node-label {
        color: var(--health-selfcare);
    }

    .health-stepper__node.is-current .health-stepper__node-label { font-weight: 700; }

    @media (max-width: 575.98px) {
        /* Five names do not fit across 360px. The dots keep the sense of position and
           the badge above names the current question in full. */
        .health-stepper__node-label { display: none; }
        .health-stepper__nodes::before { top: 15px; }
    }

    /* --- Option buttons: clinical interactive tiles ---
       Replaces the raw strip of inline text pills with structured clinical tiles.
       Each tile has an icon tile, strong label, and clear tactile lift on hover.
       Responsive: 2-3 columns on tablet/desktop, single/double on narrow phones. */
    .health-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .health-option {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        min-height: 54px;
        padding: 12px 18px;
        border-radius: var(--health-r-md);
        /* DESIGN.md's glass control at clinical card scale: subtle translucent white
           body with an emerald boundary, lift-not-zoom hover. */
        border: 1.5px solid rgba(21, 128, 61, 0.18);
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(8px);
        color: #1F2937;
        font-size: 0.9375rem;
        font-weight: 600;
        text-align: left;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
        cursor: pointer;
        transition: background-color var(--health-motion), border-color var(--health-motion),
            color var(--health-motion), transform var(--health-motion),
            box-shadow var(--health-motion);
    }

    .health-option__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: var(--health-selfcare-bg);
        border: 1px solid var(--health-selfcare-border);
        color: var(--health-selfcare);
        font-size: 1rem;
        transition: transform var(--health-motion), background-color var(--health-motion);
    }

    .health-option__label {
        flex-grow: 1;
        line-height: 1.35;
    }

    .health-option:hover {
        background: #ffffff;
        border-color: var(--health-selfcare);
        color: var(--health-selfcare);
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgba(4, 120, 87, 0.12);
    }

    .health-option:hover .health-option__icon {
        background: var(--health-selfcare);
        border-color: var(--health-selfcare);
        color: #ffffff;
        transform: scale(1.08);
    }

    .health-option:active {
        background: #D1FAE5;
        border-color: #059669;
        transform: translateY(0);
        box-shadow: 0 2px 4px rgba(4, 120, 87, 0.1);
    }

    @media (max-width: 480px) {
        .health-options {
            grid-template-columns: 1fr;
            gap: 10px;
        }
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
        border-radius: var(--health-r-sm);
        border: 1px solid rgba(21, 128, 61, 0.22);
        background: #fff;
        padding: 12px 16px;
        width: 100%;
        box-shadow: inset 0 1px 2px rgba(15, 63, 52, 0.04);
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

    /* Inside running text, where the 44px tap target would open a hole the height of
       three lines. Reached by mouse and screen reader here, not by thumb. */
    .health-link--inline {
        min-height: 0;
        padding: 0;
        display: inline;
    }

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

    /* The pressed button keeps its colour and instead carries a spinner: dropping
       opacity on the primary action reads as "disabled", and a disabled-looking
       button is exactly the ambiguity this state is meant to remove. */
    .health-btn[aria-busy="true"] {
        cursor: progress;
        opacity: 0.86;
    }

    /* Hidden until the button is busy. Present in the DOM from first paint so
       switching it on moves no text. */
    .health-btn__spinner {
        display: none;
        flex-shrink: 0;
        width: 15px;
        height: 15px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.45);
        border-top-color: #fff;
        animation: healthSpin 700ms linear infinite;
    }

    /* Ghost buttons put ink on white, so their spinner needs a dark ring. */
    .health-btn-ghost .health-btn__spinner {
        border-color: rgba(4, 120, 87, 0.3);
        border-top-color: var(--health-selfcare);
    }

    .health-btn[aria-busy="true"] .health-btn__spinner { display: block; }

    /* A travel arrow promises movement; while the answer is being read there is
       nowhere to point yet, and the spinner is the honest icon for the moment.
       Marked on the icon itself so Back's left arrow is covered by the same rule. */
    .health-btn[aria-busy="true"] .health-btn__arrow,
    .health-btn[aria-busy="true"] > i.bi { display: none; }

    @keyframes healthSpin {
        to { transform: rotate(360deg); }
    }

    /* A frozen spinner still has to say "working", so under reduced motion it stops
       turning and the label does the talking. The one thing that must not happen is
       the animation becoming the only signal. */
    @media (prefers-reduced-motion: reduce) {
        .health-btn__spinner { animation: none !important; }
    }

    /* DESIGN.md's CTA button: solid #047857 emerald, matching the storefront's
       primary actions. The diagonal gradient that was here belonged to the admin
       panel, where gradients are explicitly permitted; storefront CTA is solid,
       with text measuring 5.48:1 on #047857 and 7.68:1 on hover #065F46. */
    .health-btn-primary {
        background: var(--health-selfcare);
        border-color: var(--health-selfcare);
        color: #fff;
    }

    .health-btn-primary:hover {
        background: #065F46;
        border-color: #065F46;
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(5, 150, 105, 0.2);
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

    /* --- Outcome cards ---
       DESIGN.md's opaque warning card: the level's own tint across the whole surface,
       a full 1.5px border in the level's border colour, and the level's colour on the
       title. Three signals — tint, icon, words — so the level survives greyscale, and
       a solid border rather than a heavier edge on one side, because a card weighted
       left says nothing a full border does not say and reads as a template. */
    .health-outcome {
        border: 1.5px solid var(--health-line);
        border-radius: var(--health-r-lg);
    }

    .health-outcome--emergency {
        background: var(--health-emergency-bg);
        border-color: var(--health-emergency-border);
    }
    .health-outcome--refer_doctor {
        background: var(--health-refer-bg);
        border-color: var(--health-refer-border);
    }
    .health-outcome--pharmacist_review {
        background: var(--health-pharmacist-bg);
        border-color: var(--health-pharmacist-border);
    }
    .health-outcome--self_care {
        background: var(--health-selfcare-bg);
        border-color: var(--health-selfcare-border);
    }

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

    /* --- Briefing / Guidance card ---
       DESIGN.md's glass card: clean frosted white, fine emerald-tinted border,
       and soft shadow. The cyan hairline and radial tint that sat here were a
       tell of machine-generated card decoration. */
    .health-brief {
        position: relative;
        border-radius: var(--health-r-lg);
        border: 1px solid rgba(21, 128, 61, 0.14);
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(6px);
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
        padding: 24px;
        overflow: hidden;
        animation: healthRise 380ms cubic-bezier(.2, .8, .2, 1) both;
    }

    @media (min-width: 768px) {
        .health-brief { padding: 32px; }
    }

    .health-brief__head {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding-bottom: 20px;
        border-bottom: 1px solid #E2E8F0;
    }

    /* Section rhythm: 24px between sections, 8px inside one. The old result page put
       everything on the same 16px gap, which is why it read as one flat column. */
    .health-brief__why,
    .health-brief__section { margin-top: 24px; }

    .health-brief__why {
        padding: 18px 20px;
        border-radius: 12px;
        background: #F0FDF4;
        border: 1px solid #BBF7D0;
        color: #14532D;
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
    .health-product {        /* Off the design system's #E2E8F0 hairline on purpose. Measured on white:
           #E2E8F0 is 1.23:1 and #CBD5E1 is 1.48:1, both under the 3:1 that a boundary
           this card carries has to clear — the same drug-safety flag rides on it. #6B7280
           is 4.83:1 and stays a real edge at a glance and in greyscale. */
        border: 1px solid var(--health-line);
        border-radius: 14px;
        padding: 16px;
        background: #fff;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        overflow-wrap: anywhere;
        transition: transform var(--health-motion), box-shadow var(--health-motion), border-color var(--health-motion);
    }

    .health-product:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        border-color: #4B5563; /* 7.56:1 — the hover state only ever darkens the edge */
    }

    /* Border + icon + text together, so the flag survives greyscale printing and
       colour-blind viewing. Keeps its own tint rather than the new white surface: this
       is the one card whose background is a warning, not decoration. */
    .health-product--flagged {
        border-color: var(--health-refer-border);
        background: var(--health-refer-bg);
    }

    /* `.health-product:hover` is a two-class selector, so it outranks the rule above
       and would repaint the amber edge grey on pointerover — on the one card whose
       border is a drug-safety signal. Restated here at matching weight. */
    .health-product--flagged:hover {
        border-color: var(--health-refer);
    }

    .health-product__price {
        font-size: 1.0625rem;
        font-weight: 700;
        color: #047857;
        /* Tabular figures so prices line up between the two columns. */
        font-variant-numeric: tabular-nums;
    }

    /* --- Product usage clamp and disclosure ---
       `products.usage` is the crawled clinical monograph: 3,000+ characters on a drug
       like Smecgim, which dwarfed the card it sat in. The summary above the toggle is
       the dose line; everything after it is reference material the reader opts into.
       Native <details>, so it works with JavaScript disabled and needs no ARIA. */
    .health-product__usage-details { margin-top: 4px; }

    .health-product__usage-toggle {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--health-selfcare);
        cursor: pointer;
        list-style: none;
        padding: 2px 0;
        user-select: none;
        transition: color var(--health-motion);
    }

    .health-product__usage-toggle::-webkit-details-marker { display: none; }

    .health-product__usage-toggle:hover {
        color: #065F46;
        text-decoration: underline;
    }

    .health-product__usage-chevron {
        font-size: 0.75rem;
        transition: transform 200ms ease;
    }

    .health-product__usage-details[open] .health-product__usage-chevron { transform: rotate(180deg); }

    .health-product__usage-full {
        max-height: 200px;
        overflow-y: auto;
        white-space: pre-line;
        line-height: 1.6;
        font-size: 0.8125rem;
        background: #F8FAFC;
        border: 1px solid #E2E8F0;
        border-radius: var(--health-r-sm);
        padding: 10px;
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

    /* --- Product list, inside its own tab ---
       On its own screen the list is no longer a footnote under a wall of prose, so
       the cards carry a little more weight: a category chip, the price on its own
       line, and the action pinned to the card floor so two cards in a row line up
       even when one has no usage text. */
    .health-product__category {
        display: inline-block;
        align-self: flex-start;
        padding: 3px 10px;
        margin-bottom: 8px;
        border-radius: var(--health-r-pill);
        background: var(--health-selfcare-bg);
        color: var(--health-selfcare);
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.01em;
    }

    .health-product__name {
        font-size: 0.9375rem;
        font-weight: 700;
        line-height: 1.45;
        color: var(--health-ink);
        margin-bottom: 6px;
    }

    /* The price is the number a customer scans for, so it gets its own row rather
       than trailing the category on the same line. */
    .health-product__price {
        display: flex;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 10px;
    }

    .health-product__price-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #047857;
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.01em;
    }

    .health-product__stock {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.8125rem;
        font-weight: 500;
        color: var(--health-selfcare);
    }

    /* `margin-top: auto` on the link floor keeps the buttons of a row aligned. */
    .health-product__action {
        margin-top: auto;
        padding-top: 12px;
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

    /* --- Result page: sticky journey sidebar ---
       Two columns on a wide screen: the rail pinned at the left, everything else in the
       scrolling column. The shell widens for it because the rail is a diagram, not
       prose — the reading measure of the content column stays where it was.

       Below 992px the rail is removed, not stacked. A sticky column becomes a long
       block at the top of the page on a phone, which is exactly the "long rambling
       column" the tab hub was introduced to end, and the audit tab already lists the
       same five answers in full. The outcome is the one thing the rail owns that
       nothing else restates, so it stays in the verdict card above — which never left. */
    .health-shell--rail { max-width: 1080px; }

    /* A card, not bare text. The rail sat on the page background with no surface at
       all, while the two tab panels beside it are both bordered white cards — so the
       right-hand column read as the page and the rail read as a caption that had
       escaped. Same surface recipe as `.health-card`: translucent white, blur, the
       shared shadow, so it is recognisably one of the page's blocks.

       The `max-height` + `overflow-y: auto` pair is gone with it. It let a tall rail
       scroll inside its own box, but the rail is five short nodes (~400px) that fit any
       laptop viewport, so all it did was put a second scrollbar on a page that is
       already scrolling. */
    .health-rail {
        position: sticky;
        /* 62px navbar (`fixed-top` in the layout) plus a 16px breath, so the top of the
           rail never tucks under the header. */
        top: 78px;
        padding: 22px;
        border-radius: var(--health-r-lg);
        /* The same glass recipe as `.health-card`, so the rail is recognisably one of
           the page's blocks rather than a second, greyer material. */
        border: 1px solid rgba(21, 128, 61, 0.14);
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(20px);
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
    }

    .health-rail__flags {
        margin-bottom: 18px;
    }

    .health-rail__head {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding-bottom: 16px;
        margin-bottom: 18px;
        border-bottom: 1px solid #E2E8F0;
    }

    /* Sentence case and 15px, not the 12px uppercase eyebrow this had as a bare
       heading. Uppercase at that size reads as a section label in a long document; here
       it is the name of a panel, next to two panels that set their names the same way.
       Tightened leading because the tile beside it fixes the block's height. */
    .health-rail__title {
        font-family: var(--health-display);
        font-size: 0.9375rem;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: -0.005em;
        color: var(--health-ink);
        margin-bottom: 4px;
    }

    .health-rail__hint {
        font-size: 0.8125rem;
        line-height: 1.4;
        color: var(--health-ink-soft);
        margin-bottom: 0;
    }

    @media (min-width: 992px) {
        .health-shell--rail {
            display: grid;
            /* The rail is a fixed track: its labels and the answer fragments under them
               are short by construction, so a flexible track would only let it drift
               wider than its content needs. */
            grid-template-columns: 264px minmax(0, 1fr);
            gap: 48px;
            align-items: start;
        }

        /* Two cells, two explicit placements. The content is one wrapper element and the
           rail sits beside it in the same row.

           This used to place `.health-rail` with `grid-row: 1 / -1` against an implicit
           grid and let every other child fall into its own row. There was no explicit
           row line for `-1` to resolve against, so it resolved to row 1, the rail's
           382px height stretched that row, and the 182px header left a 200px hole above
           the disclaimer. One cell per column is the whole fix. */
        .health-shell__content { grid-column: 2; }

        .health-shell--rail > .health-rail { grid-column: 1; grid-row: 1; }
    }

    @media (max-width: 991.98px) {
        /* Removed, not stacked — see the comment above. `display: none` also takes it
           out of the accessibility tree, so the same five answers are not announced
           twice on a phone (the audit tab has them). */
        .health-rail { display: none; }
    }

    /* --- Result page: journey rail ---
       The answers that produced the conclusion, as a numbered path rather than a list
       behind a chevron.

       Three layouts were tried and two were wrong, both for the same reason: a path is
       one-directional, and any wrap breaks that. One row of six inside the 768px shell
       left 116px a node, so alternating values wrapped to two lines and the rest read
       as a cramped table. A 3x2 grid fixed the width but cut the line — node 3 to node 4
       had no connector, and row two restarted at the left, so the path visibly ran
       backwards.

       So the rail never wraps and it leaves the document flow: it is a sticky sidebar
       (`.health-rail`, defined below), the answers run down its single column, and the
       tab hub scrolls past it. That is the one arrangement where the rail can be both a
       sequence and permanent — pinned, it stays in view while the reader moves through
       the content it summarises. Reading order, DOM order and visual order are the same
       thing; and because nothing here is `:nth-child`-dependent, a five-node rail works
       exactly as well as a six-node one. */
    .health-journey {
        /* Half the dot's width (32px / 2 = 16px), so the dot sits flush at the step's left edge
           and the connector, centred on the same x, runs through it rather than beside it. */
        --journey-rail-x: 16px;
        --journey-dot: 32px;
        position: relative;
        margin: 0;
        padding: 0;
        list-style: none;
        display: grid;
        gap: 18px;
    }

    .health-journey__step {
        position: relative;
        padding-left: 48px;
        /* A node's number rides the rail, so its box is the anchor for both the dot and
           the connector that leaves it. */
        min-height: var(--journey-dot);
    }

    /* A node's number, not a bare dot: the rail asserts a sequence, and a sequence is
       the one thing six disconnected labels do not say. The number is real text, not a
       pseudo-element, so it is announced with the step it belongs to. */
    .health-journey__dot {
        position: absolute;
        top: 0;
        left: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        width: var(--journey-dot);
        height: var(--journey-dot);
        border-radius: var(--health-r-pill);
        background: #fff;
        border: 2px solid var(--health-selfcare-border);
        color: var(--health-selfcare);
        font-size: 0.8125rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
        /* Nodes pop in order, carrying the sequence down the rail. Delayed past the
           card's own entrance so the two do not read as one jitter. */
        animation: healthDotIn 300ms cubic-bezier(.2, .8, .2, 1) both;
        animation-delay: calc(var(--journey-index, 0) * 60ms + 200ms);
    }

    @keyframes healthDotIn {
        from { opacity: 0; transform: scale(.4); }
        to { opacity: 1; transform: none; }
    }

    /* Connector: from this node's dot to the next one. Every node but the last, no
       column arithmetic — which is why the count can change without the path breaking. */
    .health-journey__step:not(:last-child)::after {
        content: '';
        position: absolute;
        top: calc(var(--journey-dot) + 4px);
        left: var(--journey-rail-x);
        bottom: -22px;
        width: 2px;
        background: var(--health-selfcare-border);
    }

    /* The outcome node closes the path, so it wears the level's colour. Four separate
       rules rather than one base plus four colour overrides: at (0,3,0) the colour
       rules would tie the base and win only on source order, which is the exact shape
       of the bug that made `.health-product--flagged` lose to its own hover. */
    .health-journey__step--outcome .health-journey__dot--emergency {
        background: var(--health-emergency-bg);
        border-color: var(--health-emergency-border);
        color: var(--health-emergency);
    }

    .health-journey__step--outcome .health-journey__dot--refer_doctor {
        background: var(--health-refer-bg);
        border-color: var(--health-refer-border);
        color: var(--health-refer);
    }

    .health-journey__step--outcome .health-journey__dot--pharmacist_review {
        background: var(--health-pharmacist-bg);
        border-color: var(--health-pharmacist-border);
        color: var(--health-pharmacist);
    }

    .health-journey__step--outcome .health-journey__dot--self_care {
        background: var(--health-selfcare-bg);
        border-color: var(--health-selfcare-border);
        color: var(--health-selfcare);
    }

    .health-journey__label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--health-ink-mute);
        margin-bottom: 3px;
    }

    .health-journey__value {
        display: block;
        font-family: var(--health-display);
        font-size: 0.9375rem;
        font-weight: 600;
        line-height: 1.45;
        color: var(--health-ink);
        overflow-wrap: anywhere;
    }

    /* The conclusion is the line the visitor came for; the five before it are
       supporting evidence and are set one step down. */
    .health-journey__step--outcome .health-journey__value {
        font-size: 1rem;
        font-weight: 700;
    }

    /* --- Result page: tab hub ---
       Built on Bootstrap's pill plugin, which app.js already bundles, so the tab is
       an anchor with a real `aria-selected` rather than a div wearing a role.

       Segmented glass control instead of an underline rule: the three panels are the
       page's document sections (interpretation, care, evidence), and a segmented
       control states that they are peers. It also carries the apothecary-glass surface
       — a recessed track with one raised member — which the underline rule did not. */
    .health-hub__tabbar {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-bottom: 20px;
        padding: 5px;
        background: rgba(241, 245, 249, 0.85);
        border: 1px solid rgba(21, 128, 61, 0.10);
        border-radius: var(--health-r-md);
        /* Inset, so the track reads as a recessed channel with the active tab raised
           out of it — depth from the shadow, not from a second tint. */
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .health-hub__tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 15px;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--health-ink-soft);
        text-decoration: none;
        border: 1px solid transparent;
        border-radius: calc(var(--health-r-md) - 4px);
        transition: color var(--health-motion), background-color var(--health-motion),
            border-color var(--health-motion), box-shadow var(--health-motion);
    }

    .health-hub__tab:hover {
        color: var(--health-selfcare);
        background: rgba(255, 255, 255, 0.7);
        border-color: rgba(21, 128, 61, 0.12);
    }

    .health-hub__tab.active {
        background: #ffffff;
        color: var(--health-selfcare);
        border-color: rgba(21, 128, 61, 0.16);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    }

    .health-hub__tab:focus-visible {
        outline: none;
        box-shadow: var(--health-focus-ring);
    }

    /* Panels settle in on switch: opacity plus a 4px rise, nothing that moves the
       surrounding layout. */
    .health-hub__panels .tab-pane.fade {
        display: none;
        opacity: 0;
        transform: translateY(4px);
        transition: opacity 220ms cubic-bezier(.2, .8, .2, 1), transform 220ms cubic-bezier(.2, .8, .2, 1);
    }

    .health-hub__panels .tab-pane.fade.show {
        display: block;
        opacity: 1;
        transform: none;
    }

    /* Stacked panels reuse the briefing shell, so they need the same vertical rhythm
       the briefing gets internally. Without this two panels share an edge. */
    .health-hub__panels .health-brief + .health-brief {
        margin-top: 16px;
    }

    /* No script: every panel reads in document order and the tab bar goes away, so
       nothing on the page looks like a control that does not work. The hub is an
       enhancement over a page whose default is "show everything". */
    html:not(.js) .health-hub__panels .tab-pane.fade {
        display: block;
        opacity: 1;
        transform: none;
    }

    html:not(.js) .health-hub__tabbar { display: none; }

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

        /* The rail is a position indicator for a scrolling reader; paper has no scroll
           position, and A4 is narrower than the sidebar's breakpoint anyway. Hidden
           unconditionally rather than left to the media query, because a landscape or
           wide page would otherwise print the two-column shell. Nothing is lost: the
           audit tab prints every step label with its full answer, which is the same
           content the rail truncates. */
        .health-rail { display: none !important; }
        .health-shell--rail { display: block !important; max-width: none !important; }

        /* The gradient hairline prints as a grey band across every card. */
        .health-card::before { display: none !important; }

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

        /* The blanket border above flattens the severity cue. On paper the level's own
           border colour is what is left once the tint and the surface are gone, so it
           comes back — thicker, in the level's colour, and evenly on all four sides. */
        .health-outcome,
        .health-verdict {
            border-width: 2px !important;
            box-shadow: none !important;
        }

        .health-verdict--emergency { border-color: var(--health-emergency) !important; }
        .health-verdict--refer_doctor { border-color: var(--health-refer) !important; }
        .health-verdict--pharmacist_review { border-color: var(--health-pharmacist) !important; }
        .health-verdict--self_care { border-color: var(--health-selfcare) !important; }

        .health-outcome--emergency { border-color: var(--health-emergency) !important; }
        .health-outcome--refer_doctor { border-color: var(--health-refer) !important; }
        .health-outcome--pharmacist_review { border-color: var(--health-pharmacist) !important; }
        .health-outcome--self_care { border-color: var(--health-selfcare) !important; }

        .health-product--flagged {
            border: 2px solid var(--health-refer) !important;
            background: #fff !important;
        }

        /* Tabs are a screen affordance. On paper every panel prints, in order; the
           bar itself is noise, and an unopened tab would otherwise print blank. */
        .health-hub__tabbar { display: none !important; }
        .health-hub__panels .tab-pane,
        .health-hub__panels .tab-pane.fade {
            display: block !important;
            opacity: 1 !important;
            transform: none !important;
        }

        /* Every disclosure is open on paper: nobody can click a chevron on a printout,
           and the clinic needs the dosage line and the warnings in full. */
        .health-product__usage-details { display: block !important; }
        .health-product__usage-toggle { display: none !important; }
        .health-product__usage-full {
            display: block !important;
            max-height: none !important;
            overflow: visible !important;
            border: none !important;
            padding: 0 !important;
            background: transparent !important;
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

        /* The pharmacist-written original must be on the printout taken to the
           clinic, not hidden behind a <details> the paper cannot open. */
        details[open] > summary,
        details > summary { list-style: none; }
        details > *:not(summary) { display: block !important; }
    }
</style>
