/**
 * Tim kiem bang giong noi cho o tim kiem o header.
 *
 * Nhan dien chay hoan toan trong browser bang Web Speech API: khong byte audio nao
 * roi may khach. Server chi nhan TEXT qua /api/voice/normalize de sua lech phat am
 * vung mien. Do 2026-09-01: ca hai AI provider cua repo deu khong co STT tieng Viet
 * dung duoc — mot model tra "pm", mot model bia ten thuoc.
 *
 * Khong co SpeechRecognition (Firefox de no sau co dom.webspeech.recognition.enable)
 * thi file nay khong lam gi: icon kinh lup giu nguyen cho va o tim kiem go nhu cu.
 */

/** Tu dung neu khong nghe duoc gi: mic sang vo han la mot loi, khong phai tinh nang. */
const SILENCE_MS = 8000;

/**
 * Ngan sach cua loi goi chuan hoa. Cao hon search.voice.ai_timeout (8s) mot chut
 * de server con kip tu tra loi truoc khi client tu bo — neu client bo truoc thi
 * moi loi server deu hien thanh "timeout" va khong ai debug duoc.
 *
 * Khong co moc nay thi mot proxy treo de nut disabled vinh vien: do duoc
 * 2026-09-02, state='working' va disabled=true van con sau 9 giay.
 */
const NORMALIZE_TIMEOUT_MS = 10000;

/** Khoa sessionStorage de tu dong nghe sau khi reload vi vua cap quyen micro. */
const AUTOSTART_KEY = 'pharmacy_voice_autostart';

function lang(key, fallback = '') {
    return window.PharmacyVoiceLang?.[key] ?? fallback;
}

function errorMessage(code) {
    const errors = window.PharmacyVoiceLang?.errors ?? {};

    return errors[code] ?? errors.generic ?? '';
}

class VoiceSearch {
    constructor(button, input) {
        this.button = button;
        this.input = input;
        this.form = input.closest('form');
        this.recognition = null;
        this.silenceTimer = null;
        this.heard = '';
        this.previous = '';

        // Dinh ca trang: bam lai cung vo ich trong phien nay vi khach da bam Chan
        // hoac Permissions-Policy chan. setState() doc co nay nen nut khong bi bat
        // lai — truoc day setState('idle') bat lai ngay trong cung mot task.
        this.denied = false;

        this.status = this.createStatus();

        // Dai o xa o trong DOM, nen buoc quan he bang aria thay vi bang vi tri.
        this.input.setAttribute('aria-describedby', 'voice-status');

        this.positionBound = () => this.positionStatus();
        this.button.addEventListener('click', () => this.toggle());
        this.status.addEventListener('click', (event) => this.handleStatusClick(event));
        window.addEventListener('scroll', this.positionBound, { passive: true });
        window.addEventListener('resize', this.positionBound, { passive: true });
        document.addEventListener('pharmacy:header-state', this.positionBound);

        // Dai la mot overlay position: fixed o z-index 10000, tren ca header. Khong
        // co duong tat nao thi mot lan bi tu choi mic de no nam do suot luot truy cap,
        // va search-autocomplete.js:106-107 cong chieu cao cua no vao offset panel —
        // do duoc 2026-09-02 o 1440px: panel tut xuong 56px thay vi 8px (dai cao 36px
        // cong 12px dem).
        this.dismissBound = (event) => this.handleOutsideClick(event);
        this.keyBound = (event) => this.handleKeydown(event);
        document.addEventListener('click', this.dismissBound);
        document.addEventListener('keydown', this.keyBound);

        this.watchMicrophonePermission();

        // Dai nam o cuoi <body> nen thu tu Tab tu nhien dat no sau ca trang — do
        // 2026-09-02: 60 lan Tab khong toi duoc nut trong dai. KHONG chuyen no vao
        // trong header de sua: .site-header co backdrop-filter, thu tao containing
        // block cho position: fixed, nen moi toa do positionStatus() tinh ra se lech
        // dung bang offset cua header (do duoc: top 200 -> 250, left 100 -> 130).
        // Thay vao do bac cau Tab bang JS, hai chieu.
        this.tabBound = (event) => this.handleTab(event);
        this.input.addEventListener('keydown', this.tabBound);
        this.status.addEventListener('keydown', this.tabBound);
    }
    createStatus() {
        const status = document.createElement('div');
        status.className = 'voice-status';
        // role + aria-live: nguoi dung trinh doc man hinh phai biet may dang nghe,
        // dang xu ly hay da loi, ma khong bi lay mat focus khoi o tim kiem.
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.id = 'voice-status';

        document.body.appendChild(status);

        return status;
    }

    /**
     * Dat dai ngay duoi form, kep trong viewport o CA HAI mep.
     *
     * Chi kep mep trai la du cho desktop nhung khong du cho 375px: do duoc
     * 2026-09-02, mot cau dai day dai toi left=114 right=465 trong viewport 375px va
     * nut "Dung nguyen van" ra ngoai 75px. position: fixed khong sinh scrollbar nen
     * phan do khong voi toi duoc. GUTTER = 12 giong
     * SearchAutocomplete.positionSuggestions().
     */
    positionStatus() {
        if (!this.status.hasAttribute('data-visible') || !this.form?.isConnected) {
            return;
        }

        const GUTTER = 12;
        const rect = this.form.getBoundingClientRect();
        const width = this.status.getBoundingClientRect().width;
        const maxLeft = Math.max(GUTTER, window.innerWidth - GUTTER - width);

        this.status.style.top = `${rect.bottom + 8}px`;
        this.status.style.left = `${Math.min(Math.max(GUTTER, rect.left), maxLeft)}px`;
    }

    setState(state) {
        this.button.dataset.state = state;
        this.button.setAttribute(
            'aria-label',
            {
                listening: lang('listening', 'Đang nghe'),
                // 'working' roi vao nhanh mac dinh truoc day, nen mot nut dang disabled
                // va dang quay tu gioi thieu la "Tim bang giong noi".
                working: lang('working', 'Đang xử lý...'),
            }[state] ?? lang('start', 'Tìm bằng giọng nói')
        );
        this.button.setAttribute('aria-busy', state === 'working' ? 'true' : 'false');

        const icon = this.button.querySelector('i');

        if (icon) {
            // bi-mic-fill luc nghe, bi-arrow-repeat luc cho server: hinh dang doi chu
            // khong chi doi mau, vi mau mot minh khong truyen duoc trang thai.
            icon.className = {
                listening: 'bi bi-mic-fill',
                working: 'bi bi-arrow-repeat',
            }[state] ?? 'bi bi-mic';
        }

        this.button.disabled = state === 'working' || this.denied;

        if (state === 'working') {
            this.showStatus(lang('working', 'Đang xử lý...'), 'info');
        }
    }

    showStatus(text, tone = 'info') {
        this.status.dataset.tone = tone;
        this.status.textContent = text;
        this.status.setAttribute('data-visible', 'true');
        this.positionStatus();
    }
    /**
     * Dai ket qua. Nhan doi theo `confident` cua server: tang T1 phien am la mot
     * PHONG DOAN, khong phai mot lan khop catalog, va trinh bay hai thu do giong
     * nhau la dieu khien "Par xe ta mon nam tram" trong nhu mot ket qua that.
     */
    showResult(data) {
        const heard = data.heard || this.heard;
        const confident = data.confident !== false;

        this.status.dataset.tone = 'info';
        this.status.textContent = '';

        const label = document.createElement('span');
        label.textContent = confident
            ? `${lang('heard', 'Nghe được')}: `
            : `${lang('lowConfidence', 'Đoán là')}: `;

        const value = document.createElement('span');
        value.className = 'voice-status__heard';
        // textContent, khong innerHTML: day la chu do nguoi dung noi ra.
        value.textContent = heard;

        const undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'voice-status__undo';
        undo.dataset.voiceUndo = 'true';
        undo.textContent = lang('undo', 'Dùng nguyên văn');

        this.status.append(label, value, undo);
        this.status.setAttribute('data-visible', 'true');
        this.positionStatus();
    }

    hideStatus() {
        this.status.removeAttribute('data-visible');
    }

    handleStatusClick(event) {
        if (!event.target.closest('[data-voice-undo]')) {
            return;
        }

        this.applyText(this.heard);
        this.hideStatus();
        this.input.focus();
    }

    handleKeydown(event) {
        if (event.key !== 'Escape') {
            return;
        }

        // Dang nghe thi Escape huy luon session, khong chi tat dai.
        if (this.recognition) {
            this.stop();
        }

        this.dismiss();
    }

    handleTab(event) {
        if (event.key !== 'Tab') {
            return;
        }

        const undo = this.status.querySelector('[data-voice-undo]');

        if (!undo || !this.status.hasAttribute('data-visible')) {
            return;
        }

        if (event.target === this.input && !event.shiftKey) {
            event.preventDefault();
            undo.focus();

            return;
        }

        if (event.target === undo && event.shiftKey) {
            event.preventDefault();
            this.input.focus();
        }
    }

    handleOutsideClick(event) {
        if (
            !this.status.hasAttribute('data-visible')
            || this.status.contains(event.target)
            || this.button.contains(event.target)
            || this.input.contains(event.target)
        ) {
            return;
        }

        this.dismiss();
    }

    /**
     * Duong ra duy nhat cua dai. hideStatus() la chi tiet ben trong; dismiss() con
     * ban lai pharmacy:header-state de search-autocomplete.js do lai offset ngay,
     * thay vi cho lan cuon ke tiep.
     */
    dismiss() {
        this.hideStatus();
        document.dispatchEvent(new CustomEvent('pharmacy:header-state'));
    }
    toggle() {
        // 'working' la luc dang cho server: nut da disabled nen click khong toi duoc
        // day, nhung ban phim va script tu dong thi co.
        if (this.button.dataset.state === 'working') {
            return;
        }

        if (this.recognition) {
            this.stop();

            return;
        }

        this.start();
    }

    start() {
        const Recognition = window.SpeechRecognition ?? window.webkitSpeechRecognition;
        const recognition = new Recognition();

        recognition.lang = 'vi-VN';
        // continuous = false: mot truy van tim kiem la mot cau. Nghe lien tuc lam mic
        // sang vo han va an pin cho mot o ma khach da noi xong.
        recognition.continuous = false;
        // interimResults: hien chu dang nghe ngay trong o — nguoi noi can thay may
        // dang nghe. Chi ket qua isFinal moi duoc gui len server.
        recognition.interimResults = true;
        // 3 alternative: server thu tung cai, nen mot lan nghe lech o lua chon dau
        // van con hai co hoi khop catalog ma khong ton them loi goi AI nao.
        recognition.maxAlternatives = 3;

        recognition.onresult = (event) => this.handleResult(event);
        recognition.onerror = (event) => this.handleError(event);
        recognition.onend = () => this.handleEnd();

        this.previous = this.input.value;
        this.heard = '';
        this.setState('listening');
        this.hideStatus();

        try {
            recognition.start();
        } catch {
            // Gan SAU start(): start() nem nghia la khong co session nao, nen cung
            // khong co `end` nao de nha this.recognition. Gan truoc la de nut chet
            // vinh vien — toggle() se mai vao nhanh stop() cua mot session khong ton
            // tai. Do duoc 2026-09-02: log ["new","start"] roi ["stop","stop"].
            this.fail('generic');

            return;
        }

        this.recognition = recognition;
        this.armSilenceTimer();
    }

    /**
     * abort(), khong phai stop(): theo spec Web Speech, stop() nghia la "ngung nghe
     * va co tra ket qua tu audio da nhan", con abort() moi la huy. Nhan cua nut la
     * "Dang nghe, bam de dung" — khach bam de HUY, khong phai de chot mot cau ho
     * vua nhan ra la noi sai.
     */
    stop() {
        this.release();
        this.recognition?.abort();
        this.recognition = null;
        this.setState('idle');
    }

    /**
     * Mot cho duy nhat nha tai nguyen cua mot session, goi duoc nhieu lan. */
    release() {
        clearTimeout(this.silenceTimer);
        this.silenceTimer = null;
    }

    /**
     * Tu bat dau nghe sau khi trang tai lai.
     *
     * Co trong sessionStorage chi song trong tab nay va chi mot lan: xoa ngay khi
     * doc. Khong xoa thi moi lan tai lai sau (F5, back/forward) cung bat mic, va mot
     * trang ban thuoc tu mo micro la hanh vi khach khong dong y.
     *
     * Cho cu click dau tien thay vi start() ngay: Chrome doi user gesture, nen loi
     * goi ngay sau reload chi nhan not-allowed — vua khoa nut vua bay dai loi "can
     * cho phep micro" cho mot quyen khach vua cap. Click dau tien la gesture hop le
     * va cung la luc khach that su muon dung.
     */
    autostartFromSession() {
        if (sessionStorage.getItem(AUTOSTART_KEY) !== '1') {
            return;
        }

        sessionStorage.removeItem(AUTOSTART_KEY);

        const kickstart = () => {
            // Bam thang vao nut mic thi toggle() da lo xong truoc do.
            if (this.recognition || this.button.dataset.state === 'working') {
                return;
            }

            this.denied = false;
            this.start();
        };

        document.addEventListener('click', kickstart, { once: true });
    }

    /**
     * Cap quyen micro xong thi tai lai trang roi tu nghe.
     *
     * Quyen doi o hai duong: prompt cua trinh duyet luc bam mic (Chrome hay tra
     * `not-allowed` cho chinh session vua duoc cap, va trang da khoa nut vi loi do),
     * hoac khach mo o khoa tren thanh dia chi de bo Chan. Ca hai chi het ket sau khi
     * trang tai lai, nen doi sang 'granted' la reload. Chi lan doi moi reload —
     * trang moi doc lai quyen da 'granted' nen khong co vong lap.
     */
    watchMicrophonePermission() {
        if (!navigator.permissions?.query) {
            return;
        }

        navigator.permissions.query({ name: 'microphone' }).then((status) => {
            if (status.state === 'granted') {
                return;
            }

            status.addEventListener('change', () => {
                if (status.state !== 'granted') {
                    return;
                }

                sessionStorage.setItem(AUTOSTART_KEY, '1');
                window.location.reload();
            });
        }).catch(() => {
            // Safari/Firefox cu nem TypeError cho 'microphone'. Khong quan sat duoc thi
            // khach bam mic nhu truoc, khong co gi hong.
        });
    }

    armSilenceTimer() {
        this.release();
        // abort() cung ly do nhu stop(): het 8 giay im lang thi huy, khong chot mot
        // cau nghe duoc nua voi.
        this.silenceTimer = setTimeout(() => this.stop(), SILENCE_MS);
    }
    handleResult(event) {
        const result = event.results[event.results.length - 1];
        const alternatives = Array.from(result)
            .map((alternative) => alternative.transcript.trim())
            .filter((transcript) => transcript !== '')
            .slice(0, 3);

        if (!result.isFinal) {
            // Chi gia han khi CON dang nghe. Gia han ca o ket qua cuoi la de mot timer
            // 8 giay song qua luc normalize() dang bay: stop() gio tu dat state, nen
            // no se bat lai nut giua luc request chua ve va mot click nua mo session
            // thu hai de len session chua xong.
            this.armSilenceTimer();

            if (alternatives.length === 0) {
                return;
            }

            // Chi hien tam trong o, chua goi server: ket qua tam doi tung tu.
            this.input.value = alternatives[0];

            return;
        }

        this.release();

        if (alternatives.length === 0) {
            return;
        }

        this.heard = alternatives[0];
        this.normalize(alternatives);
    }

    handleError(event) {
        this.release();

        // not-allowed: khach da bam Chan, hoac Permissions-Policy chan.
        if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
            this.denied = true;
        }

        this.fail(event.error);
    }

    handleEnd() {
        this.release();
        this.recognition = null;

        // Ket qua tam da ghi vao o nhung khong co ket qua cuoi nao: chu do chua qua
        // /api/voice/normalize, khong co dai, khong co nut nao de bo no. Tra o ve chu
        // cu thay vi de "pa ra xe ta" nam do roi Enter tim nguyen van.
        if (this.heard === '' && this.input.value !== this.previous) {
            this.input.value = this.previous;
        }

        if (this.button.dataset.state === 'listening') {
            this.setState('idle');
        }
    }
    async normalize(alternatives) {
        this.setState('working');

        // AbortController chu khong phai Promise.race: bo ma khong huy request thi
        // ket noi van treo va mot luot noi sau van xep hang sau no.
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), NORMALIZE_TIMEOUT_MS);

        try {
            const response = await fetch(lang('url', '/api/voice/normalize'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ alternatives }),
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            this.applyText(data.text || this.heard);
            this.showResult(data);
        } catch (error) {
            // Endpoint loi, 429, timeout hay mat mang: chu khach noi van o trong o va
            // sua duoc, nhung phai noi ro la buoc sua phat am KHONG chay. Truoc day
            // nhanh nay hien y nhu mot lan thanh cong — do duoc 2026-09-02: 429 ra
            // dai "Nghe duoc: thuoc ho" voi tone info.
            this.applyText(this.heard);
            this.showStatus(
                errorMessage(error?.name === 'AbortError' ? 'timeout' : 'network'),
                'error'
            );
        } finally {
            clearTimeout(timer);
            this.setState('idle');
        }
    }

    /**
     * Dien o roi ban event `input`.
     *
     * Khong goi ham nao cua panel goi y: search-autocomplete.js da lang `input`, nen
     * ban event lai dung dung duong go tay — cung debounce 300ms, cung AbortController,
     * cung dieu huong ban phim. Khong tu submit form: nghe sai ma nhay sang trang thuoc
     * khac la hong theo huong te nhat trong mot app nha thuoc.
     *
     * Thu tu ba dong duoi day la co y. handleFocus() cua panel fetch NGAY, khong qua
     * debounce (search-autocomplete.js:151-158), con handleInput() thi co debounce
     * 300ms. Nen: xoa o truoc de lan focus khong fetch gi (chuoi rong duoi minChars),
     * roi moi dien va ban `input` de dung mot request debounce di ra. Do 2026-09-02:
     * thu tu cu (dien -> input -> focus) cho HAI request /api/search/suggestions y het
     * nhau; chi doi cho focus len dau van con hai khi o dang co chu cu, vi focus fetch
     * theo chu cu do.
     */
    applyText(text) {
        this.input.value = '';
        this.input.focus();
        this.input.value = text;
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    fail(code) {
        // Chi tra o ve chu cu khi luot noi CHUA cho ket qua nao. Engine co the ban
        // mot loi muon sau khi normalize da thanh cong — do duoc 2026-09-02: o di tu
        // "canxi" sang "men vi sinh" roi bi keo ve "canxi" kem dai do.
        if (this.heard === '') {
            this.input.value = this.previous;
        }

        this.setState('idle');
        this.showStatus(errorMessage(code), 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('[data-voice-search]');
    const input = document.getElementById('header-search');
    // isSecureContext: tren http://<LAN-IP>:8123 (dien thoai vao bang IP noi bo) symbol
    // van ton tai nen truoc day data-voice-ready duoc dat, kinh lup bi an, va nut mic loi
    // o moi lan bam. Web Speech chi chay o secure context; http://localhost la trustworthy
    // origin nen dev server khong bi anh huong (do 2026-09-02: isSecureContext=true).
    const supported = window.isSecureContext
        && ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

    if (!button || !input || !supported) {
        // Khong ho tro: khong dat data-voice-ready, nen CSS giu icon kinh lup va nut
        // micro khong bao gio hien. Nguoi dung Firefox khong thay mot nut bam khong chay.
        return;
    }

    document.documentElement.setAttribute('data-voice-ready', 'true');

    const voice = new VoiceSearch(button, input);
    voice.autostartFromSession();
});

export default VoiceSearch;
