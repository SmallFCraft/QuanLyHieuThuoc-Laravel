/**
 * Placeholder gõ từng chữ cho mọi ô tìm kiếm.
 *
 * Thuần trang trí: <label class="visually-hidden"> mới là tên thật của ô và không
 * đổi theo animation — screen reader cần một tên ổn định, đọc lại tên ô mỗi 45ms là
 * không dùng được. Không có JS thì placeholder do server render đứng yên và tìm kiếm
 * chạy như cũ.
 */
const TYPE_MS = 45;
const ERASE_MS = 22;
const HOLD_MS = 1800;
const GAP_MS = 200;

class PlaceholderTypewriter {
    constructor(input, phraseSets) {
        this.input = input;
        this.phraseSets = phraseSets;
        this.phrases = phraseSets.narrow;
        this.fallback = input.getAttribute('placeholder') ?? '';
        this.phraseIndex = 0;
        this.charIndex = 0;
        this.erasing = false;
        this.timer = null;
        this.visible = true;

        this.tickBound = () => this.tick();
        this.stopBound = () => this.stop();
        this.resumeBound = () => this.resume();
        this.visibilityBound = () => (document.hidden ? this.pause() : this.resume());
    }

    /**
     * Bộ chuỗi cho khổ ô hiện tại. Ô hẹp lại ~90px khi header đầy đủ, nên chuỗi dài
     * dùng ở đó sẽ bị text-overflow cắt — xem chú thích trong config/search.php.
     *
     * Đo lòng trong ô thay vì đọc data-header-scrolled: trạng thái đó chỉ nới ô trên
     * điện thoại (dưới 992px), còn trên desktop ô luôn đủ rộng cho bộ dài.
     *
     * Đọc lúc bắt đầu mỗi câu, không phải mỗi ký tự: đổi bộ giữa câu sẽ cắt đứt chuỗi
     * đang gõ dở. Cũng vì vậy không cần MutationObserver theo thuộc tính.
     */
    activeSet() {
        if (this.phraseSets.wide.length === 0) {
            return this.phraseSets.narrow;
        }

        return this.fits(this.phraseSets.wide) ? this.phraseSets.wide : this.phraseSets.narrow;
    }

    /** Chuỗi dài nhất trong bộ có vừa lòng trong ô ở kích thước hiện tại. */
    fits(phrases) {
        const style = window.getComputedStyle(this.input);
        const inner = this.input.clientWidth
            - parseFloat(style.paddingLeft)
            - parseFloat(style.paddingRight);

        if (!(inner > 0)) {
            return false;
        }

        // Canvas measureText: không chèn node nào vào trang nên không gây layout.
        PlaceholderTypewriter.ruler ??= document.createElement('canvas').getContext('2d');
        PlaceholderTypewriter.ruler.font = style.font || `${style.fontSize} ${style.fontFamily}`;

        return phrases.every((phrase) => PlaceholderTypewriter.ruler.measureText(phrase).width <= inner);
    }

    start() {
        this.input.addEventListener('focus', this.stopBound);
        this.input.addEventListener('blur', this.resumeBound);
        document.addEventListener('visibilitychange', this.visibilityBound);

        // Ô tìm kiếm trang chủ nằm ~900px dưới đầu trang: gõ chữ ở một ô ngoài màn
        // hình là đốt CPU cho hiệu ứng không ai thấy.
        if ('IntersectionObserver' in window) {
            this.observer = new IntersectionObserver((entries) => {
                this.visible = entries[0].isIntersecting;
                this.visible ? this.resume() : this.pause();
            });
            this.observer.observe(this.input);
        }

        this.resume();
    }

    /** Đang gõ dở thì trả lại placeholder gốc, không để ô đứng với nửa câu. */
    pause() {
        clearTimeout(this.timer);
        this.timer = null;
        this.charIndex = 0;
        this.erasing = false;
        this.input.setAttribute('placeholder', this.fallback);
    }

    stop() {
        this.pause();
    }

    resume() {
        if (this.timer || document.hidden || !this.visible) {
            return;
        }

        // Ô đang focus hoặc đã có chữ: placeholder không hiện, và người đang gõ thì
        // chữ chạy dưới con trỏ là nhiễu.
        if (document.activeElement === this.input || this.input.value !== '') {
            return;
        }

        this.timer = setTimeout(this.tickBound, GAP_MS);
    }

    tick() {
        if (document.activeElement === this.input || this.input.value !== '') {
            this.pause();
            return;
        }

        // Đầu một câu là chỗ duy nhất an toàn để đổi bộ chuỗi: đang gõ dở mà đổi thì
        // câu bị cắt ngang giữa từ.
        if (this.charIndex === 0 && !this.erasing) {
            const set = this.activeSet();

            if (set !== this.phrases) {
                this.phrases = set;
                this.phraseIndex %= set.length;
            }
        }

        const phrase = this.phrases[this.phraseIndex];

        if (!this.erasing) {
            this.charIndex += 1;
            this.input.setAttribute('placeholder', phrase.slice(0, this.charIndex));

            if (this.charIndex >= phrase.length) {
                this.erasing = true;
                this.timer = setTimeout(this.tickBound, HOLD_MS);
                return;
            }

            this.timer = setTimeout(this.tickBound, TYPE_MS);
            return;
        }

        this.charIndex -= 1;
        this.input.setAttribute('placeholder', phrase.slice(0, Math.max(0, this.charIndex)));

        if (this.charIndex <= 0) {
            this.erasing = false;
            this.phraseIndex = (this.phraseIndex + 1) % this.phrases.length;
            this.timer = setTimeout(this.tickBound, GAP_MS);
            return;
        }

        this.timer = setTimeout(this.tickBound, ERASE_MS);
    }

    destroy() {
        clearTimeout(this.timer);
        this.observer?.disconnect();
        this.input.removeEventListener('focus', this.stopBound);
        this.input.removeEventListener('blur', this.resumeBound);
        document.removeEventListener('visibilitychange', this.visibilityBound);
        this.input.setAttribute('placeholder', this.fallback);
    }
}

function initPlaceholderTypewriter() {
    // Tôn trọng prefers-reduced-motion bằng cách giữ nguyên placeholder tĩnh, không
    // phải bỏ trống ô.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const usable = (list) => (list ?? []).filter((phrase) => typeof phrase === 'string' && phrase !== '');
    const narrow = usable(window.PharmacySearchLang?.placeholders);

    if (narrow.length === 0) {
        return;
    }

    const phraseSets = { narrow, wide: usable(window.PharmacySearchLang?.placeholdersWide) };
    const instances = [];

    document.querySelectorAll('input[name="q"]').forEach((input) => {
        if (input.dataset.placeholderTypewriter === 'true') {
            return;
        }

        input.dataset.placeholderTypewriter = 'true';

        const instance = new PlaceholderTypewriter(input, phraseSets);
        instance.start();
        instances.push(instance);
    });

    window.addEventListener('pagehide', () => {
        instances.forEach((instance) => instance.destroy());
    }, { once: true });
}

document.addEventListener('DOMContentLoaded', initPlaceholderTypewriter);

export default PlaceholderTypewriter;
