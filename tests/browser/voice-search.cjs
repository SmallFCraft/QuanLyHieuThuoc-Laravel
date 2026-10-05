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
    || 'E:/Apps/Browsers/Playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe';

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

    // --- F6b: click ra ngoài cũng tắt dải ------------------------------------------
    await go();
    await page.click(MIC);
    await E(() => { window.__err('no-speech'); window.__end(); });
    await page.mouse.click(700, 600);
    await page.waitForTimeout(300);
    check(
        'F6b click ra ngoài tắt dải trạng thái',
        !(await E(() => document.querySelector('.voice-status').hasAttribute('data-visible')))
    );

    // --- F6c: dải tắt rồi thì panel gợi ý về đúng chỗ -----------------------------
    const suggested = page.waitForResponse((r) => r.url().includes('/api/search/suggestions'));
    await page.fill('#header-search', 'vitamin');
    await suggested;
    await page.waitForTimeout(400);
    const panel = await E(() => {
        const el = document.querySelector('.search-suggestions');
        const form = document.getElementById('header-search').closest('form');

        return {
            open: getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().height > 0,
            gap: Math.round(el.getBoundingClientRect().top - form.getBoundingClientRect().bottom),
        };
    });
    check(
        'F6c panel không bị dải đẩy xuống sau khi dải tắt',
        panel.open && panel.gap < 20,
        `open=${panel.open} gap=${panel.gap}px`
    );

    // --- F9: session bỏ giữa không được để lại chữ thô không ai xử lý -------------
    await go();
    await page.fill('#header-search', 'vitamin c');
    await page.click(MIC);
    await E(() => window.__interim('pa ra xê ta'));
    await page.click(MIC);
    await E(() => window.__end());
    await page.waitForTimeout(500);
    check(
        'F9 huỷ giữa lượt thì ô về chữ cũ',
        (await E(() => document.getElementById('header-search').value)) === 'vitamin c',
        `ô=${await E(() => document.getElementById('header-search').value)}`
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

    // --- F14b: một request kể cả khi ô đang có chữ cũ ----------------------------
    await go();
    await page.fill('#header-search', 'vitamin c');
    await page.waitForTimeout(700);
    const refine = [];
    page.on('request', (r) => { if (r.url().includes('/api/search/suggestions')) { refine.push(r.url()); } });
    await page.click(MIC);
    const refined = page.waitForResponse((r) => r.url().includes('voice/normalize'));
    await E(() => { window.__final(['men vi sinh']); window.__end(); });
    await refined;
    await page.waitForTimeout(1500);
    check('F14b một request suggestions khi voice thay chữ đang có', refine.length === 1, `calls=${refine.length}`);

    // --- F8: nhãn nút nói đúng việc nó làm ----------------------------------------
    await go();
    await page.fill('#header-search', 'vitamin c');
    await page.click(MIC);
    const shown = page.waitForResponse((r) => r.url().includes('voice/normalize'));
    await E(() => { window.__final(['pa ra xê ta môn năm trăm']); window.__end(); });
    await shown;
    await page.waitForTimeout(600);
    const undoLabel = await E(() => document.querySelector('.voice-status__undo')?.textContent ?? '');
    await page.click('.voice-status__undo');
    check(
        'F8 nút áp đúng chữ ASR và nhãn nói vậy',
        undoLabel === 'Dùng nguyên văn'
            && (await E(() => document.getElementById('header-search').value)) === 'pa ra xê ta môn năm trăm',
        `nhãn=${undoLabel}`
    );

    // --- F-conf: kết quả không chắc chắn phải nói là đoán -------------------------
    await go();
    await page.route('**/api/voice/normalize', (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ text: 'thuốc ho', heard: 'thuấc ho', source: 'phonetic', confident: false }),
        })
    );
    await page.click(MIC);
    await E(() => { window.__final(['thuấc ho']); window.__end(); });
    await page.waitForTimeout(800);
    check(
        'F-conf kết quả confident=false hiện là "Đoán là"',
        (await E(() => document.querySelector('.voice-status').textContent)).includes('Đoán là')
    );
    await page.unroute('**/api/voice/normalize');

    // --- F11: contrast của chữ trong dải và của ring focus -----------------------
    await go();
    const RATIO = () => {
        const lum = (rgb) => {
            const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
            return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
        };
        const parse = (s) => (s.match(/[\d.]+/g) || [0, 0, 0]).map(Number);
        const over = (fg, bg) => { const a = fg.length > 3 ? fg[3] : 1; return [0, 1, 2].map((i) => a * fg[i] + (1 - a) * bg[i]); };
        const ratio = (a, b) => { const l1 = lum(a); const l2 = lum(b); return +(((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)).toFixed(2)); };
        return { parse, over, ratio };
    };

    // Chữ trong dải: đo trên nền dải thật, với nút undo thật do showResult() dựng.
    const textContrast = await E((fns) => {
        const { parse, over, ratio } = eval(`(${fns})`)();
        const white = [255, 255, 255];
        const strip = document.querySelector('.voice-status');
        strip.setAttribute('data-visible', 'true');
        strip.innerHTML = '<span class="voice-status__heard">x</span><button class="voice-status__undo">x</button>';
        const undo = strip.querySelector('.voice-status__undo');
        const css = (el, prop) => getComputedStyle(el).getPropertyValue(prop);
        const stripBg = over(parse(css(strip, 'background-color')), white);
        const out = {
            undo: ratio(over(parse(css(undo, 'color')), stripBg), stripBg),
            heard: ratio(over(parse(css(strip, 'color')), stripBg), stripBg),
            size: css(undo, 'font-size'),
        };
        strip.removeAttribute('data-visible');
        return out;
    }, RATIO.toString());
    check('F11 chữ nút trong dải đạt 4.5:1', textContrast.undo >= 4.5, `undo=${textContrast.undo}:1 @ ${textContrast.size}`);
    check('F11b chữ thân dải đạt 4.5:1', textContrast.heard >= 4.5, `heard=${textContrast.heard}:1`);

    // Ring focus: chỉ đọc được khi phần tử ĐANG focus, và phải đọc từ box-shadow thật
    // chứ không từ một custom property không tồn tại.
    await page.focus(MIC);
    const ringContrast = await E((fns) => {
        const { parse, over, ratio } = eval(`(${fns})`)();
        const white = [255, 255, 255];
        const mic = document.querySelector('[data-voice-search]');
        const shadow = getComputedStyle(mic).boxShadow;

        if (shadow === 'none' || shadow === '') {
            return { shadow, ratio: 0 };
        }

        const input = document.getElementById('header-search');
        const inputBg = over(parse(getComputedStyle(input).backgroundColor), white);

        return { shadow, ratio: ratio(over(parse(shadow), inputBg), inputBg) };
    }, RATIO.toString());
    check(
        'F11c ring focus của mic đạt 3:1',
        ringContrast.ratio >= 3,
        `ratio=${ringContrast.ratio}:1 shadow="${ringContrast.shadow}"`
    );

    // --- F12: nút ✕ mặc định của type=search phải bị ẩn --------------------------
    // Đây là kiểm tra SỰ CÓ MẶT CỦA LUẬT trong CSSOM, không phải sự vắng mặt của nút:
    // chrome-headless-shell trả display=block cho getComputedStyle(input,
    // '::-webkit-search-cancel-button') dù có hay không có `display: none`, và cssText
    // của pseudo-element đó rỗng — đo 2026-09-02, nên không có tín hiệu hành vi nào.
    const cancelHidden = await E(() => {
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules) {
                    if (rule.cssText?.includes('search-cancel-button') && rule.cssText.includes('display: none')) {
                        return true;
                    }
                }
            } catch { /* stylesheet khác origin */ }
        }
        return false;
    });
    check('F12 nút ✕ native bị ẩn để không chồng nút mic', cancelHidden);

    // --- lòng trong ô không được đổi khi mic thay icon ---------------------------
    const inner = await E(() => {
        const input = document.getElementById('header-search');
        const pad = (side) => parseFloat(getComputedStyle(input).getPropertyValue(`padding-${side}`));
        const measure = () => Math.round(input.clientWidth - pad('left') - pad('right'));
        const had = document.documentElement.hasAttribute('data-voice-ready');
        const withVoice = measure();
        document.documentElement.removeAttribute('data-voice-ready');
        const without = measure();
        if (had) { document.documentElement.setAttribute('data-voice-ready', 'true'); }
        return { withVoice, without };
    });
    check('lòng trong ô không đổi khi mic thay icon', inner.withVoice === inner.without, `${inner.withVoice} vs ${inner.without}`);

    // --- L2: nút đang chờ server phải tự nói là đang xử lý ------------------------
    // Route treo để state đứng ở 'working' đủ lâu mà đọc thuộc tính, không đua với
    // NORMALIZE_TIMEOUT_MS.
    await go();
    await page.route('**/api/voice/normalize', () => {});
    await page.click(MIC);
    await E(() => { window.__final(['men vi sinh']); window.__end(); });
    await page.waitForTimeout(400);
    const working = await E(() => {
        const mic = document.querySelector('[data-voice-search]');

        return { busy: mic.getAttribute('aria-busy'), label: mic.getAttribute('aria-label') };
    });
    check(
        'L2 nút đang xử lý báo đúng trạng thái',
        working.busy === 'true' && working.label === 'Đang xử lý...',
        `aria-busy=${working.busy} aria-label=${working.label}`
    );
    await page.unroute('**/api/voice/normalize');

    // --- L3: Tab bắc cầu hai chiều giữa ô và nút trong dải ------------------------
    // Dải là con cuối của <body> nên thứ tự Tab tự nhiên không tới được nó; cầu JS
    // trong voice-search.js phải nối một bước, và Shift+Tab phải trả focus về ô.
    await go();
    await page.click(MIC);
    const bridged = page.waitForResponse((r) => r.url().includes('voice/normalize'));
    await E(() => { window.__final(['men vi sinh']); window.__end(); });
    await bridged;
    await page.waitForTimeout(600);
    await page.focus('#header-search');
    await page.keyboard.press('Tab');
    const onUndo = await E(() => document.activeElement.classList.contains('voice-status__undo'));
    await page.keyboard.press('Shift+Tab');
    const backOnInput = await E(() => document.activeElement.id === 'header-search');
    check('L3 Tab bắc cầu hai chiều giữa ô và nút trong dải', onUndo && backOnInput, `undo=${onUndo} back=${backOnInput}`);

    // --- L3b: dải vẫn dùng toạ độ viewport, không bị header bắt làm containing block ---
    // Chuyển dải vào trong header là cách "sửa" Tab bị loại: .site-header có
    // backdrop-filter, thứ tạo containing block cho position: fixed, nên toạ độ
    // positionStatus() tính ra sẽ lệch đúng bằng offset của header.
    const aligned = await E(() => {
        const s = document.querySelector('.voice-status');
        const f = document.getElementById('header-search').closest('form');

        return Math.abs(s.getBoundingClientRect().top - (f.getBoundingClientRect().bottom + 8)) < 2;
    });
    check('L3b dải neo đúng 8px dưới form', aligned);

    // --- L4: khối mobile không được ghi đè khai báo reduced-motion ----------------
    // Kiểm KHAI BÁO, không phải chuyển động cảm nhận được: storefront.css:2140 đã ép
    // mọi transition-duration về 0.01ms dưới prefers-reduced-motion, nên đây là dọn
    // một khai báo tự mâu thuẫn.
    const reduced = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 375, height: 812 } });
    const rp = await reduced.newPage();
    await rp.goto(BASE, { waitUntil: 'domcontentloaded' });
    const headerTransition = await rp.evaluate(() => getComputedStyle(document.querySelector('.site-header')).transitionProperty);
    check('L4 reduced-motion tắt transition của header ở 375px', !headerTransition.includes('padding'), `transition=${headerTransition}`);
    await reduced.close();

    // --- P1: quyền micro đổi sang granted thì đặt cờ rồi reload --------------------
    // addInitScript chạy TRƯỚC mọi script của trang. Stub gắn sau `load` là quá muộn:
    // watchMicrophonePermission() đã query() đối tượng permissions thật của trình
    // duyệt ở DOMContentLoaded rồi, nên đổi state trên stub không ai nghe.
    await context.addInitScript(() => {
        const perm = { state: 'prompt', listeners: [] };
        const status = {
            get state() { return perm.state; },
            addEventListener(type, fn) { if (type === 'change') perm.listeners.push(fn); },
        };

        Object.defineProperty(navigator, 'permissions', {
            value: { query: () => Promise.resolve(status) },
            configurable: true,
        });

        window.__grant = () => {
            perm.state = 'granted';
            perm.listeners.forEach((fn) => fn());
        };

        // Trang mới xóa cờ ngay khi đọc, nên init script là thời điểm duy nhất còn
        // thấy nó sau khi tải lại.
        window.__flagSeen = sessionStorage.getItem('pharmacy_voice_autostart');
        window.__log = window.__log || [];
    });

    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(MIC);
    await E(() => { sessionStorage.removeItem('pharmacy_voice_autostart'); });

    const afterReload = page.waitForEvent('framenavigated');
    await E(() => window.__grant());
    await afterReload;
    await page.waitForSelector(MIC);

    const flagSeen = await E(() => window.__flagSeen);
    check('P1 cấp quyền micro xong đặt cờ rồi tải lại trang', flagSeen === '1', `flag lúc tải lại=${flagSeen}`);

    // --- P1b: cờ chỉ dùng một lần --------------------------------------------------
    const clearedAfterRead = await E(() => sessionStorage.getItem('pharmacy_voice_autostart'));
    check('P1b đọc cờ xong thì xóa ngay (không tự bật lại ở lần tải sau)', clearedAfterRead === null, `flag=${clearedAfterRead}`);

    // --- P1c: reload rồi thì cú click đầu tiên mở mic ------------------------------
    await E(() => { window.__log = []; });
    await page.click('body');
    check(
        'P1c sau reload, cú click đầu tiên mở mic luôn',
        (await log()).includes('start'),
        `log=${JSON.stringify(await log())}`
    );

    // --- P1d: bấm thẳng nút mic thì không start hai lần ----------------------------
    // Listener kickstart nằm ở document (bubble) nên nút mic chạy toggle() trước;
    // tới lượt nó thì this.recognition đã có và nó phải đứng yên.
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(MIC);
    await E(() => { sessionStorage.setItem('pharmacy_voice_autostart', '1'); });
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(MIC);
    await E(() => { window.__log = []; });
    await page.click(MIC);
    const startCount = (await log()).filter((entry) => entry === 'start').length;
    check('P1d bấm thẳng nút mic sau reload không start hai lần', startCount === 1, `start=${startCount}`);

    // --- P2: trang thường không đụng cờ --------------------------------------------
    await E(() => { sessionStorage.removeItem('pharmacy_voice_autostart'); });
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(MIC);
    const untouched = await E(() => sessionStorage.getItem('pharmacy_voice_autostart'));
    check('P2 trang thường không tự đặt cờ autostart', untouched === null, `flag=${untouched}`);

    await browser.close();

    const failed = results.filter((r) => !r.pass);
    console.log(`\n${results.length - failed.length}/${results.length} đạt`);
    process.exit(failed.length === 0 ? 0 : 1);
})();

