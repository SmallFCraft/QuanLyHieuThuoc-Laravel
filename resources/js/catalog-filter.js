/**
 * Category filter, shared by the catalog sidebar and the header dropdown.
 *
 * The crawled catalog carries 51 categories, so both lists scroll inside a fixed
 * frame and a text box narrows them down. Matching runs on a folded copy of each
 * name rendered into data-folded by VietnameseText::fold(), the same helper the
 * product search uses — so "duoc my pham" finds "Dược mỹ phẩm" here too.
 *
 * Progressive enhancement: both lists are complete and usable in HTML, and the
 * mobile catalog panel opens and closes through <details> without any of this.
 */
const FOLD_STRIPS = /[̀-ͯ]/g;

/**
 * Folds typed input the way VietnameseText::fold() folds the haystack.
 *
 * The two agree on Vietnamese but not beyond it: PHP runs Str::ascii(), so "Ðông y"
 * (U+00D0, not the Vietnamese U+0110) folds to "dong y" server-side and "ong y" here,
 * and "Vitamin B₁₂" to "vitamin b12" there but "vitamin b" here.
 *
 * ponytail: every character NFD leaves outside [a-z0-9] becomes a word separator and
 * words are matched individually as substrings, so those cases still find their row
 * ("ong" is inside "dong y"). Measured on all 51 crawled category names: identical
 * output both sides. Closing the gap properly needs Str::ascii()'s transliteration
 * table shipped to the browser — worth it only once a category name actually carries
 * one of those characters.
 */
function fold(value) {
    return value
        .normalize('NFD')
        .replace(FOLD_STRIPS, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function initCategoryFilter(panel) {
    const input = panel.querySelector('[data-catalog-filter-search]');
    const list = panel.querySelector('[data-catalog-filter-list]');

    if (!input || !list) {
        return;
    }

    // Matched on data-folded, which the catalog sidebar and the header dropdown both
    // render onto their category rows — so one filter serves two different markups.
    const links = [...list.querySelectorAll('[data-folded]')];

    input.addEventListener('input', () => {
        const words = fold(input.value).split(' ').filter(Boolean);
        let visible = 0;

        links.forEach((link) => {
            const haystack = link.dataset.folded ?? '';
            const matches = words.every((word) => haystack.includes(word));

            link.hidden = !matches;
            visible += matches ? 1 : 0;
        });

        list.classList.toggle('is-empty', visible === 0);
    });

    // Bootstrap keeps the dropdown markup around after it closes, so a stale query
    // would still be filtering the list the next time it opens.
    panel.closest('.dropdown')?.addEventListener('hidden.bs.dropdown', () => {
        if (input.value === '') {
            return;
        }

        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-catalog-filter]').forEach(initCategoryFilter);
});
