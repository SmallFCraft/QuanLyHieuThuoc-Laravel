/**
 * Kiểm tra máy trạng thái của resources/js/voice-search.js trong một browser thật.
 *
 * CHẠY TAY, không nằm trong CI — cùng lối với tests/load/voice-normalize.k6.js:
 * repo này không có JS test runner và plan này không thêm dependency nào.
 *
 *   php artisan serve --port=8123      (hoặc preview_start config "pharmacy")
 *   NODE_PATH="E:/Apps/npm-global/node_modules" node tests/browser/voice-search.cjs
 *
 * Vì sao mock SpeechRecognition: Web Speech cần mic thật và người nói thật, nên
 * các nhánh lỗi (từ chối quyền, start() ném, hết 8s im lặng) không kiểm được bằng
 * tay một cách tất định. Mock để mỗi nhánh là một lời gọi hàm.
 */

/**
 * playwright-core + executablePath tường minh: máy này không có `playwright` nào
 * resolve được (không có node_modules cục bộ, npx --package cũng trượt), còn
 * PLAYWRIGHT_BROWSERS_PATH đang giữ chromium-1237 trong khi playwright-core 1.60.0
 * đi tìm -1223. Đo 2026-09-02.
 */
const CHROMIUM_EXE = process.env.PW_CHROMIUM
    || 'E:/Apps/Browsers/Playwright/chromium_headless_shell-1237/chrome-headless-shell-win64/chrome-headless-shell.exe';

function loadChromium() {
    try {
        return require('playwright-core').chromium;
    } catch {
        // Máy có bản cài bình thường thì dùng nó.
        return require('playwright').chromium;
    }
}

const BASE = process.env.BASE_URL || 'http://localhost:8123';
const MIC = '[data-voice-search]';
const results = [];

function check(name, pass, detail = '') {
    results.push({ name, pass, detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? `  — ${detail}` : ''}`);
}

const MOCK = () => {
    class MockRecognition {
        constructor() {
            this.lang = '';
            this.continuous = false;
            this.interimResults = false;
            this.maxAlternatives = 1;
            this.onresult = null;
            this.onerror = null;
            this.onend = null;
            window.__sr = this;
            window.__log = window.__log || [];
            window.__log.push('new');
        }
        start() {
            window.__log.push('start');
            if (window.__throwOnStart) {
                throw new DOMException('busy', 'InvalidStateError');
            }
        }
        stop() { window.__log.push('stop'); }
        abort() { window.__log.push('abort'); }
    }

    window.SpeechRecognition = MockRecognition;
    window.webkitSpeechRecognition = MockRecognition;

    const build = (texts, isFinal) => {
        const alts = texts.map((transcript) => ({ transcript, confidence: 0.9 }));
        const result = alts.slice();
        result.isFinal = isFinal;
        return { results: [result] };
    };

    window.__final = (texts) => window.__sr.onresult(build(texts, true));
    window.__interim = (text) => window.__sr.onresult(build([text], false));
    window.__err = (error) => window.__sr.onerror({ error });
    window.__end = () => window.__sr.onend();
    window.__reset = () => { window.__log.length = 0; };
};

(async () => {
    const browser = await loadChromium().launch({ executablePath: CHROMIUM_EXE });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addInitScript(MOCK);
    const page = await context.newPage();

    const go = async () => {
        await page.goto(BASE, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector(MIC);
    };

    const E = (fn, arg) => page.evaluate(fn, arg);
    const state = () => page.getAttribute(MIC, 'data-state');
    const log = () => E(() => window.__log.slice());

    // --- F1: start() ném một lần không được làm nút chết vĩnh viễn -----------------
    await go();
    await E(() => { window.__throwOnStart = true; });
    await page.click(MIC);
    await E(() => { window.__throwOnStart = false; window.__reset(); });
    await page.click(MIC);
    check(
        'F1 mic vẫn khởi động lại được sau khi start() ném',
        (await log()).includes('start'),
        `log=${JSON.stringify(await log())}`
    );

    // --- F1b: hai lần bấm nhanh không được để máy tưởng vẫn đang nghe -------------
    await go();
    await page.click(MIC);
    await page.click(MIC);
    await E(() => window.__end());
    await E(() => window.__reset());
    await page.click(MIC);
    check(
        'F1b bấm lại sau khi dừng thì mở session mới',
        (await log()).includes('new'),
        `log=${JSON.stringify(await log())}`
    );

    // --- F1c: kết quả cuối không được để timer 8 giây bật lại nút giữa lúc chờ ----
    await go();
    await page.route('**/api/voice/normalize', () => {});
    await page.click(MIC);
    // Cố tình KHÔNG gọi __end(): mô phỏng engine không bắn `end` sau kết quả cuối.
    await E(() => window.__final(['men vi sinh']));
    await page.waitForTimeout(9000);
    check(
        'F1c timer im lặng không bật lại nút khi đang chờ server',
        (await state()) === 'working' && (await E(() => document.querySelector('[data-voice-search]').disabled)),
        `state=${await state()}`
    );
    await page.unroute('**/api/voice/normalize');

    // --- F2: fetch treo không được để nút disabled vĩnh viễn -----------------------
    await go();
    await page.route('**/api/voice/normalize', () => {});
    await page.click(MIC);
    await E(() => { window.__final(['men vi sinh']); window.__end(); });
    await page.waitForTimeout(11000);
    check(
        'F2 fetch treo tự bỏ sau timeout',
        (await state()) === 'idle' && !(await E(() => document.querySelector('[data-voice-search]').disabled)),
        `state=${await state()}`
    );
    await page.unroute('**/api/voice/normalize');

    // --- F4: từ chối quyền mic phải tắt nút thật -----------------------------------
    await go();
    await page.click(MIC);
    await E(() => { window.__err('not-allowed'); window.__end(); });
    check(
        'F4 nút bị tắt sau khi bị từ chối quyền',
        await E(() => document.querySelector('[data-voice-search]').disabled)
    );

    // --- F5: lỗi đến muộn không được xoá kết quả đã áp ----------------------------
    await go();
    await page.fill('#header-search', 'canxi');
    await page.click(MIC);
    const settled = page.waitForResponse((r) => r.url().includes('voice/normalize'));
    await E(() => { window.__final(['men vi sinh']); window.__end(); });
    await settled;
    await page.waitForTimeout(500);
    const applied = await E(() => document.getElementById('header-search').value);
    await E(() => window.__err('network'));
    await page.waitForTimeout(300);
    check(
        'F5 lỗi muộn không xoá kết quả đã áp',
        (await E(() => document.getElementById('header-search').value)) === applied,
        `trước=${applied} sau=${await E(() => document.getElementById('header-search').value)}`
    );

    // --- F6: dải trạng thái phải tắt được -----------------------------------------
    await go();
    await page.click(MIC);
    await E(() => { window.__err('no-speech'); window.__end(); });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    check(
        'F6 Escape tắt dải trạng thái',
        !(await E(() => document.querySelector('.voice-status').hasAttribute('data-visible')))
    );

    // --- F7: 429 phải hiện như lỗi, không như thành công --------------------------
    await go();
    await page.route('**/api/voice/normalize', (route) =>
        route.fulfill({ status: 429, contentType: 'application/json', body: '{"message":"Too Many Attempts."}' })
    );
    await page.click(MIC);
    await E(() => { window.__final(['thuốc ho']); window.__end(); });
    await page.waitForTimeout(900);
    check(
        'F7 429 hiện tone lỗi',
        (await E(() => document.querySelector('.voice-status').dataset.tone)) === 'error',
        `tone=${await E(() => document.querySelector('.voice-status').dataset.tone)}`
    );
    await page.unroute('**/api/voice/normalize');

    // --- F10: dải không được tràn ra ngoài viewport ở 375px -----------------------
    await page.setViewportSize({ width: 375, height: 812 });
    await go();
    await page.click(MIC);
    const long = page.waitForResponse((r) => r.url().includes('voice/normalize'));
    await E(() => { window.__final(['cho tôi hỏi thuốc giảm đau hạ sốt cho trẻ em dưới hai tuổi']); window.__end(); });
    await long;
    await page.waitForTimeout(600);
    const clipped = await E(() => {
        const undo = document.querySelector('.voice-status__undo');
        return undo ? Math.round(undo.getBoundingClientRect().right - window.innerWidth) : null;
    });
    check('F10 nút trong dải không bị cắt ở 375px', clipped !== null && clipped <= 0, `cắt=${clipped}px`);

    // --- F14: một lượt voice chỉ được gọi suggestions một lần --------------------
    await page.setViewportSize({ width: 1440, height: 900 });
    await go();
    const calls = [];
    page.on('request', (r) => { if (r.url().includes('/api/search/suggestions')) { calls.push(r.url()); } });
    await page.click(MIC);
    const done = page.waitForResponse((r) => r.url().includes('voice/normalize'));
    await E(() => { window.__final(['men vi sinh']); window.__end(); });
    await done;
    await page.waitForTimeout(1500);
    check('F14 một request suggestions cho mỗi lượt voice', calls.length === 1, `calls=${calls.length}`);

    await browser.close();

    const failed = results.filter((r) => !r.pass);
    console.log(`\n${results.length - failed.length}/${results.length} đạt`);
    process.exit(failed.length === 0 ? 0 : 1);
})();

