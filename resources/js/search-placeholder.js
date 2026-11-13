/**
 * Placeholder go tung chu cho moi o tim kiem.
 *
 * Thuan trang tri: <label class="visually-hidden"> moi la ten that cua o va khong
 * doi theo animation — screen reader can mot ten on dinh, doc lai ten o moi 45ms la
 * khong dung duoc. Khong co JS thi placeholder do server render dung yen va tim kiem
 * chay nhu cu.
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
     * Bo chuoi cho kho o hien tai. O hep lai ~90px khi header day du, nen chuoi dai
     * dung o do se bi text-overflow cat — xem chu thich trong config/search.php.
     *
     * Do long trong o thay vi doc data-header-scrolled: trang thai do chi noi o tren
     * dien thoai (duoi 992px), con tren desktop o luon du rong cho bo dai.
     *
     * Doc luc bat dau moi cau, khong phai moi ky tu: doi bo giua cau se cat dut chuoi
     * dang go do. Cung vi vay khong can MutationObserver theo thuoc tinh.
     */
    activeSet() {
        if (this.phraseSets.wide.length === 0) {
            return this.phraseSets.narrow;
        }

        return this.fits(this.phraseSets.wide) ? this.phraseSets.wide : this.phraseSets.narrow;
    }

    /** Chuoi dai nhat trong bo co vua long trong o o kich thuoc hien tai. */
    fits(phrases) {
        const style = window.getComputedStyle(this.input);
        const inner = this.input.clientWidth
            - parseFloat(style.paddingLeft)
            - parseFloat(style.paddingRight);

        if (!(inner > 0)) {
            return false;
        }

        // Canvas measureText: khong chen node nao vao trang nen khong gay layout.
        PlaceholderTypewriter.ruler ??= document.createElement('canvas').getContext('2d');
        PlaceholderTypewriter.ruler.font = style.font || `${style.fontSize} ${style.fontFamily}`;

        return phrases.every((phrase) => PlaceholderTypewriter.ruler.measureText(phrase).width <= inner);
    }

    start() {
        this.input.addEventListener('focus', this.stopBound);
        this.input.addEventListener('blur', this.resumeBound);
        document.addEventListener('visibilitychange', this.visibilityBound);

        // O tim kiem trang chu nam ~900px duoi dau trang: go chu o mot o ngoai man
        // hinh la dot CPU cho hieu ung khong ai thay.
        if ('IntersectionObserver' in window) {
            this.observer = new IntersectionObserver((entries) => {
                this.visible = entries[0].isIntersecting;
                this.visible ? this.resume() : this.pause();
            });
            this.observer.observe(this.input);
        }

        this.resume();
    }

    /** Dang go do thi tra lai placeholder goc, khong de o dung voi nua cau. */
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

        // O dang focus hoac da co chu: placeholder khong hien, va nguoi dang go thi
        // chu chay duoi con tro la nhieu.
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

        // Dau mot cau la cho duy nhat an toan de doi bo chuoi: dang go do ma doi thi
        // cau bi cat ngang giua tu.
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
    // Ton trong prefers-reduced-motion bang cach giu nguyen placeholder tinh, khong
    // phai bo trong o.
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
