/**
 * Tìm kiếm bằng giọng nói cho ô tìm kiếm ở header.
 *
 * Nhận diện chạy hoàn toàn trong browser bằng Web Speech API: không byte audio nào
 * rời máy khách. Server chỉ nhận TEXT qua /api/voice/normalize để sửa lệch phát âm
 * vùng miền. Đo 2026-09-01: cả hai AI provider của repo đều không có STT tiếng Việt
 * dùng được — một model trả "pm", một model bịa tên thuốc.
 *
 * Không có SpeechRecognition (Firefox để nó sau cờ dom.webspeech.recognition.enable)
 * thì file này không làm gì: icon kính lúp giữ nguyên chỗ và ô tìm kiếm gõ như cũ.
 */

/** Tự dừng nếu không nghe được gì: mic sáng vô hạn là một lỗi, không phải tính năng. */
const SILENCE_MS = 8000;

/**
 * Ngân sách của lời gọi chuẩn hoá. Cao hơn search.voice.ai_timeout (8s) một chút
 * để server còn kịp tự trả lỗi trước khi client tự bỏ — nếu client bỏ trước thì
 * mọi lỗi server đều hiện thành "timeout" và không ai debug được.
 *
 * Không có mốc này thì một proxy treo để nút disabled vĩnh viễn: đo được
 * 2026-09-02, state='working' và disabled=true vẫn còn sau 9 giây.
 */
const NORMALIZE_TIMEOUT_MS = 10000;

/** Khóa sessionStorage để tự động nghe sau khi reload vì vừa cấp quyền micro. */
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

        // Dính cả trang: bấm lại cũng vô ích trong phiên này vì khách đã bấm Chặn
        // hoặc Permissions-Policy chặn. setState() đọc cờ này nên nút không bị bật
        // lại — trước đây setState('idle') bật lại ngay trong cùng một task.
        this.denied = false;

        this.status = this.createStatus();

        // Dải ở xa ô trong DOM, nên buộc quan hệ bằng aria thay vì bằng vị trí.
        this.input.setAttribute('aria-describedby', 'voice-status');

        this.positionBound = () => this.positionStatus();
        this.button.addEventListener('click', () => this.toggle());
        this.status.addEventListener('click', (event) => this.handleStatusClick(event));
        window.addEventListener('scroll', this.positionBound, { passive: true });
        window.addEventListener('resize', this.positionBound, { passive: true });
        document.addEventListener('pharmacy:header-state', this.positionBound);

        // Dải là một overlay position: fixed ở z-index 10000, trên cả header. Không
        // có đường tắt nào thì một lần bị từ chối mic để nó nằm đó suốt lượt truy cập,
        // và search-autocomplete.js:106-107 cộng chiều cao của nó vào offset panel —
        // đo được 2026-09-02 ở 1440px: panel tụt xuống 56px thay vì 8px (dải cao 36px
        // cộng 12px đệm).
        this.dismissBound = (event) => this.handleOutsideClick(event);
        this.keyBound = (event) => this.handleKeydown(event);
        document.addEventListener('click', this.dismissBound);
        document.addEventListener('keydown', this.keyBound);

        this.watchMicrophonePermission();

        // Dải nằm ở cuối <body> nên thứ tự Tab tự nhiên đặt nó sau cả trang — đo
        // 2026-09-02: 60 lần Tab không tới được nút trong dải. KHÔNG chuyển nó vào
        // trong header để sửa: .site-header có backdrop-filter, thứ tạo containing
        // block cho position: fixed, nên mọi toạ độ positionStatus() tính ra sẽ lệch
        // đúng bằng offset của header (đo được: top 200 -> 250, left 100 -> 130).
        // Thay vào đó bắc cầu Tab bằng JS, hai chiều.
        this.tabBound = (event) => this.handleTab(event);
        this.input.addEventListener('keydown', this.tabBound);
        this.status.addEventListener('keydown', this.tabBound);
    }
    createStatus() {
        const status = document.createElement('div');
        status.className = 'voice-status';
        // role + aria-live: người dùng trình đọc màn hình phải biết máy đang nghe,
        // đang xử lý hay đã lỗi, mà không bị lấy mất focus khỏi ô tìm kiếm.
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.id = 'voice-status';

        document.body.appendChild(status);

        return status;
    }

    /**
     * Đặt dải ngay dưới form, kẹp trong viewport ở CẢ HAI mép.
     *
     * Chỉ kẹp mép trái là đủ cho desktop nhưng không đủ cho 375px: đo được
     * 2026-09-02, một câu dài đẩy dải tới left=114 right=465 trong viewport 375px và
     * nút "Dùng nguyên văn" ra ngoài 75px. position: fixed không sinh scrollbar nên
     * phần đó không với tới được. GUTTER = 12 giống
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
                // 'working' rơi vào nhánh mặc định trước đây, nên một nút đang disabled
                // và đang quay tự giới thiệu là "Tìm bằng giọng nói".
                working: lang('working', 'Đang xử lý...'),
            }[state] ?? lang('start', 'Tìm bằng giọng nói')
        );
        this.button.setAttribute('aria-busy', state === 'working' ? 'true' : 'false');

        const icon = this.button.querySelector('i');

        if (icon) {
            // bi-mic-fill lúc nghe, bi-arrow-repeat lúc chờ server: hình dạng đổi chứ
            // không chỉ đổi màu, vì màu một mình không truyền được trạng thái.
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
     * Dải kết quả. Nhãn đổi theo `confident` của server: tầng T1 phiên âm là một
     * PHỎNG ĐOÁN, không phải một lần khớp catalog, và trình bày hai thứ đó giống
     * nhau là điều khiến "Par xê ta môn năm trăm" trông như một kết quả thật.
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
        // textContent, không innerHTML: đây là chữ do người dùng nói ra.
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

        // Đang nghe thì Escape huỷ luôn session, không chỉ tắt dải.
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
     * Đường ra duy nhất của dải. hideStatus() là chi tiết bên trong; dismiss() còn
     * bắn lại pharmacy:header-state để search-autocomplete.js đo lại offset ngay,
     * thay vì chờ lần cuộn kế tiếp.
     */
    dismiss() {
        this.hideStatus();
        document.dispatchEvent(new CustomEvent('pharmacy:header-state'));
    }
    toggle() {
        // 'working' là lúc đang chờ server: nút đã disabled nên click không tới được
        // đây, nhưng bàn phím và script tự động thì có.
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
        // continuous = false: một truy vấn tìm kiếm là một câu. Nghe liên tục làm mic
        // sáng vô hạn và ăn pin cho một ô mà khách đã nói xong.
        recognition.continuous = false;
        // interimResults: hiện chữ đang nghe ngay trong ô — người nói cần thấy máy
        // đang nghe. Chỉ kết quả isFinal mới được gửi lên server.
        recognition.interimResults = true;
        // 3 alternative: server thử từng cái, nên một lần nghe lệch ở lựa chọn đầu
        // vẫn còn hai cơ hội khớp catalog mà không tốn thêm lời gọi AI nào.
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
            // Gán SAU start(): start() ném nghĩa là không có session nào, nên cũng
            // không có `end` nào để nhả this.recognition. Gán trước là để nút chết
            // vĩnh viễn — toggle() sẽ mãi vào nhánh stop() của một session không tồn
            // tại. Đo được 2026-09-02: log ["new","start"] rồi ["stop","stop"].
            this.fail('generic');

            return;
        }

        this.recognition = recognition;
        this.armSilenceTimer();
    }

    /**
     * abort(), không phải stop(): theo spec Web Speech, stop() nghĩa là "ngừng nghe
     * và cố trả kết quả từ audio đã nhận", còn abort() mới là huỷ. Nhãn của nút là
     * "Đang nghe, bấm để dừng" — khách bấm để HUỶ, không phải để chốt một câu họ
     * vừa nhận ra là nói sai.
     */
    stop() {
        this.release();
        this.recognition?.abort();
        this.recognition = null;
        this.setState('idle');
    }

    /**
     * Một chỗ duy nhất nhả tài nguyên của một session, gọi được nhiều lần. */
    release() {
        clearTimeout(this.silenceTimer);
        this.silenceTimer = null;
    }

    /**
     * Tự bắt đầu nghe sau khi trang tải lại.
     *
     * Cờ trong sessionStorage chỉ sống trong tab này và chỉ một lần: xóa ngay khi
     * đọc. Không xóa thì mọi lần tải lại sau (F5, back/forward) cũng bật mic, và một
     * trang bán thuốc tự mở micro là hành vi khách không đồng ý.
     *
     * Chờ cú click đầu tiên thay vì start() ngay: Chrome đòi user gesture, nên lời
     * gọi ngay sau reload chỉ nhận not-allowed — vừa khoá nút vừa bày dải lỗi "cần
     * cho phép micro" cho một quyền khách vừa cấp. Click đầu tiên là gesture hợp lệ
     * và cũng là lúc khách thật sự muốn dùng.
     */
    autostartFromSession() {
        if (sessionStorage.getItem(AUTOSTART_KEY) !== '1') {
            return;
        }

        sessionStorage.removeItem(AUTOSTART_KEY);

        const kickstart = () => {
            // Bấm thẳng vào nút mic thì toggle() đã lo xong trước đó.
            if (this.recognition || this.button.dataset.state === 'working') {
                return;
            }

            this.denied = false;
            this.start();
        };

        document.addEventListener('click', kickstart, { once: true });
    }

    /**
     * Cấp quyền micro xong thì tải lại trang rồi tự nghe.
     *
     * Quyền đổi ở hai đường: prompt của trình duyệt lúc bấm mic (Chrome hay trả
     * `not-allowed` cho chính session vừa được cấp, và trang đã khoá nút vì lỗi đó),
     * hoặc khách mở ổ khoá trên thanh địa chỉ để bỏ Chặn. Cả hai chỉ hết kẹt sau khi
     * trang tải lại, nên đổi sang 'granted' là reload. Chỉ lần đổi mới reload —
     * trang mới đọc lại quyền đã 'granted' nên không có vòng lặp.
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
            // Safari/Firefox cũ ném TypeError cho 'microphone'. Không quan sát được thì
            // khách bấm mic như trước, không có gì hỏng.
        });
    }

    armSilenceTimer() {
        this.release();
        // abort() cùng lý do như stop(): hết 8 giây im lặng thì huỷ, không chốt một
        // câu nghe được nửa vời.
        this.silenceTimer = setTimeout(() => this.stop(), SILENCE_MS);
    }
    handleResult(event) {
        const result = event.results[event.results.length - 1];
        const alternatives = Array.from(result)
            .map((alternative) => alternative.transcript.trim())
            .filter((transcript) => transcript !== '')
            .slice(0, 3);

        if (!result.isFinal) {
            // Chỉ gia hạn khi CÒN đang nghe. Gia hạn cả ở kết quả cuối là để một timer
            // 8 giây sống qua lúc normalize() đang bay: stop() giờ tự đặt state, nên
            // nó sẽ bật lại nút giữa lúc request chưa về và một click nữa mở session
            // thứ hai đè lên session chưa xong.
            this.armSilenceTimer();

            if (alternatives.length === 0) {
                return;
            }

            // Chỉ hiện tạm trong ô, chưa gọi server: kết quả tạm đổi từng từ.
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

        // not-allowed: khách đã bấm Chặn, hoặc Permissions-Policy chặn.
        if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
            this.denied = true;
        }

        this.fail(event.error);
    }

    handleEnd() {
        this.release();
        this.recognition = null;

        // Kết quả tạm đã ghi vào ô nhưng không có kết quả cuối nào: chữ đó chưa qua
        // /api/voice/normalize, không có dải, không có nút nào để bỏ nó. Trả ô về chữ
        // cũ thay vì để "pa ra xê ta" nằm đó rồi Enter tìm nguyên văn.
        if (this.heard === '' && this.input.value !== this.previous) {
            this.input.value = this.previous;
        }

        if (this.button.dataset.state === 'listening') {
            this.setState('idle');
        }
    }
    async normalize(alternatives) {
        this.setState('working');

        // AbortController chứ không phải Promise.race: bỏ mà không huỷ request thì
        // kết nối vẫn treo và một lượt nói sau vẫn xếp hàng sau nó.
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
            // Endpoint lỗi, 429, timeout hay mất mạng: chữ khách nói vẫn ở trong ô và
            // sửa được, nhưng phải nói rõ là bước sửa phát âm KHÔNG chạy. Trước đây
            // nhánh này hiện y như một lần thành công — đo được 2026-09-02: 429 ra
            // dải "Nghe được: thuốc ho" với tone info.
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
     * Điền ô rồi bắn event `input`.
     *
     * Không gọi hàm nào của panel gợi ý: search-autocomplete.js đã lắng `input`, nên
     * bắn event lại dùng đúng đường gõ tay — cùng debounce 300ms, cùng AbortController,
     * cùng điều hướng bàn phím. Không tự submit form: nghe sai mà nhảy sang trang thuốc
     * khác là hỏng theo hướng tệ nhất trong một app nhà thuốc.
     *
     * Thứ tự ba dòng dưới đây là cố ý. handleFocus() của panel fetch NGAY, không qua
     * debounce (search-autocomplete.js:151-158), còn handleInput() thì có debounce
     * 300ms. Nên: xoá ô trước để lần focus không fetch gì (chuỗi rỗng dưới minChars),
     * rồi mới điền và bắn `input` để đúng một request debounce đi ra. Đo 2026-09-02:
     * thứ tự cũ (điền -> input -> focus) cho HAI request /api/search/suggestions y hệt
     * nhau; chỉ đổi chỗ focus lên đầu vẫn còn hai khi ô đang có chữ cũ, vì focus fetch
     * theo chữ cũ đó.
     */
    applyText(text) {
        this.input.value = '';
        this.input.focus();
        this.input.value = text;
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    fail(code) {
        // Chỉ trả ô về chữ cũ khi lượt nói CHƯA cho kết quả nào. Engine có thể bắn
        // một lỗi muộn sau khi normalize đã thành công — đo được 2026-09-02: ô đi từ
        // "canxi" sang "men vi sinh" rồi bị kéo về "canxi" kèm dải đỏ.
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
    // isSecureContext: trên http://<LAN-IP>:8123 (điện thoại vào bằng IP nội bộ) symbol
    // vẫn tồn tại nên trước đây data-voice-ready được đặt, kính lúp bị ẩn, và nút mic lỗi
    // ở mọi lần bấm. Web Speech chỉ chạy ở secure context; http://localhost là trustworthy
    // origin nên dev server không bị ảnh hưởng (đo 2026-09-02: isSecureContext=true).
    const supported = window.isSecureContext
        && ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

    if (!button || !input || !supported) {
        // Không hỗ trợ: không đặt data-voice-ready, nên CSS giữ icon kính lúp và nút
        // micro không bao giờ hiện. Người dùng Firefox không thấy một nút bấm không chạy.
        return;
    }

    document.documentElement.setAttribute('data-voice-ready', 'true');

    const voice = new VoiceSearch(button, input);
    voice.autostartFromSession();
});

export default VoiceSearch;
