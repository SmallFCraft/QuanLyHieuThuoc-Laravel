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

        this.status = this.createStatus();

        this.positionBound = () => this.positionStatus();
        this.button.addEventListener('click', () => this.toggle());
        this.status.addEventListener('click', (event) => this.handleStatusClick(event));
        window.addEventListener('scroll', this.positionBound, { passive: true });
        window.addEventListener('resize', this.positionBound, { passive: true });
        document.addEventListener('pharmacy:header-state', this.positionBound);
    }
    createStatus() {
        const status = document.createElement('div');
        status.className = 'voice-status';
        // role + aria-live: người dùng trình đọc màn hình phải biết máy đang nghe,
        // đang xử lý hay đã lỗi, mà không bị lấy mất focus khỏi ô tìm kiếm.
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        document.body.appendChild(status);

        return status;
    }

    /**
     * Đặt dải ngay dưới form. Cùng cách tính với panel gợi ý — xem
     * SearchAutocomplete.positionSuggestions() — nên hai thứ không chồng nhau.
     */
    positionStatus() {
        if (!this.status.hasAttribute('data-visible') || !this.form?.isConnected) {
            return;
        }

        const rect = this.form.getBoundingClientRect();

        this.status.style.top = `${rect.bottom + 8}px`;
        this.status.style.left = `${Math.max(12, rect.left)}px`;
    }

    setState(state) {
        this.button.dataset.state = state;
        this.button.setAttribute(
            'aria-label',
            state === 'listening' ? lang('listening', 'Đang nghe') : lang('start', 'Tìm bằng giọng nói')
        );

        const icon = this.button.querySelector('i');

        if (icon) {
            // bi-mic-fill lúc nghe, bi-arrow-repeat lúc chờ server: hình dạng đổi chứ
            // không chỉ đổi màu, vì màu một mình không truyền được trạng thái.
            icon.className = {
                listening: 'bi bi-mic-fill',
                working: 'bi bi-arrow-repeat',
            }[state] ?? 'bi bi-mic';
        }

        this.button.disabled = state === 'working';

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
    /** Dải kết quả: chữ nghe được cộng nút hoàn tác. */
    showHeard(heard) {
        this.status.dataset.tone = 'info';
        this.status.textContent = '';

        const label = document.createElement('span');
        label.textContent = `${lang('heard', 'Nghe được')}: `;

        const value = document.createElement('span');
        value.className = 'voice-status__heard';
        // textContent, không innerHTML: đây là chữ do người dùng nói ra.
        value.textContent = heard;

        const undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'voice-status__undo';
        undo.dataset.voiceUndo = 'true';
        undo.textContent = lang('undo', 'Hoàn tác');

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
    toggle() {
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

        this.recognition = recognition;
        this.previous = this.input.value;
        this.setState('listening');
        this.hideStatus();
        this.armSilenceTimer();

        try {
            recognition.start();
        } catch {
            // start() ném nếu đã đang chạy — coi như lỗi chung rồi về trạng thái rảnh.
            this.fail('generic');
        }
    }

    stop() {
        clearTimeout(this.silenceTimer);
        this.recognition?.stop();
    }

    armSilenceTimer() {
        clearTimeout(this.silenceTimer);
        this.silenceTimer = setTimeout(() => this.recognition?.stop(), SILENCE_MS);
    }
    handleResult(event) {
        this.armSilenceTimer();

        const result = event.results[event.results.length - 1];
        const alternatives = Array.from(result)
            .map((alternative) => alternative.transcript.trim())
            .filter((transcript) => transcript !== '')
            .slice(0, 3);

        if (alternatives.length === 0) {
            return;
        }

        if (!result.isFinal) {
            // Chỉ hiện tạm trong ô, chưa gọi server: kết quả tạm đổi từng từ.
            this.input.value = alternatives[0];

            return;
        }

        this.heard = alternatives[0];
        this.normalize(alternatives);
    }

    handleError(event) {
        clearTimeout(this.silenceTimer);

        // not-allowed: khách đã bấm Chặn, hoặc Permissions-Policy chặn. Bấm lại cũng
        // vô ích trong phiên này, nên nút bị tắt cho tới khi tải lại trang.
        if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
            this.button.disabled = true;
        }

        this.fail(event.error);
    }

    handleEnd() {
        clearTimeout(this.silenceTimer);
        this.recognition = null;

        if (this.button.dataset.state === 'listening') {
            this.setState('idle');
        }
    }
    async normalize(alternatives) {
        this.setState('working');

        try {
            const response = await fetch(lang('url', '/api/voice/normalize'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ alternatives }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            this.applyText(data.text || this.heard);
            this.showHeard(data.heard || this.heard);
        } catch {
            // Endpoint lỗi, 429 hay mất mạng: chữ khách nói vẫn ở trong ô và sửa được.
            // Một lượt nói không bao giờ thành ngõ cụt.
            this.applyText(this.heard);
            this.showHeard(this.heard);
        } finally {
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
     */
    applyText(text) {
        this.input.value = text;
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.focus();
    }

    fail(code) {
        this.input.value = this.previous;
        this.setState('idle');
        this.showStatus(errorMessage(code), 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('[data-voice-search]');
    const input = document.getElementById('header-search');
    const supported = 'SpeechRecognition' in window || 'webkitSpeechRecognition' in window;

    if (!button || !input || !supported) {
        // Không hỗ trợ: không đặt data-voice-ready, nên CSS giữ icon kính lúp và nút
        // micro không bao giờ hiện. Người dùng Firefox không thấy một nút bấm không chạy.
        return;
    }

    document.documentElement.setAttribute('data-voice-ready', 'true');

    new VoiceSearch(button, input);
});

export default VoiceSearch;
