@once
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.7/dist/purify.min.js"></script>
<script>
(function () {
    if (window.ChatbotMarkdownRenderer) {
        return;
    }

    const detailLinkPattern = /^(xem chi tiết|xem chi tiet|mở sản phẩm|mo san pham|xem danh mục|xem danh muc|mở liên kết|mo lien ket)$/i;

    function sanitizeInlineMathForPlainText(value) {
        let text = String(value ?? '');

        if (!/[\\$]/.test(text)) {
            return text;
        }

        text = text.replace(/\\\(([\s\S]*?)\\\)/g, '$1');
        text = text.replace(/\\\[([\s\S]*?)\\\]/g, '$1');
        text = text.replace(/\$\$([\s\S]*?)\$\$/g, '$1');
        text = text.replace(/\$([\s\S]*?)\$/g, '$1');

        for (let index = 0; index < 5; index += 1) {
            const updated = text.replace(/\\frac\s*\{([^{}]+)\}\s*\{([^{}]+)\}/g, '$1/$2');
            if (updated === text) {
                break;
            }
            text = updated;
        }

        const replacements = {
            '\\approx': '~',
            '\\simeq': '~',
            '\\ldots': '...',
            '\\cdots': '...',
            '\\times': 'x',
            '\\div': '/',
            '\\cdot': '*',
            '\\left': '',
            '\\right': ''
        };

        Object.entries(replacements).forEach(([needle, replacement]) => {
            text = text.split(needle).join(replacement);
        });

        return text
            .replace(/[{}]/g, '')
            .replace(/\\([a-zA-Z]+)/g, '$1')
            .replace(/\s+([,.;:!?])/g, '$1')
            .trim();
    }

    function normalizeText(value) {
        return sanitizeInlineMathForPlainText(value).replace(
            /(?:https?:\/\/|www\.)[^\s<)]+/g,
            (url, offset, source) => {
                const prefix = offset >= 2 ? source.slice(offset - 2, offset) : '';
                if (prefix === '](') {
                    return url;
                }

                const href = url.startsWith('www.') ? `https://${url}` : url;
                return `[${url}](${href})`;
            }
        );
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[character]));
    }

    function normalizeMarkdownUrl(value) {
        const rawUrl = String(value ?? '')
            .trim()
            .replace(/&amp;/g, '&')
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'");

        if (!rawUrl || /[\s"'<>]/.test(rawUrl)) {
            return '';
        }

        if (rawUrl.startsWith('www.')) {
            return `https://${rawUrl}`;
        }

        if (/^(https?:\/\/|\/|#)/i.test(rawUrl)) {
            return rawUrl;
        }

        return '';
    }

    function decorate(html) {
        let safeHtml = String(html || '');

        if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
            safeHtml = window.DOMPurify.sanitize(safeHtml, {
                USE_PROFILES: { html: true },
                ADD_ATTR: ['target', 'rel', 'class']
            });
        }

        const container = document.createElement('div');
        container.innerHTML = safeHtml;

        container.querySelectorAll('a').forEach((link) => {
            const textValue = (link.textContent || '').trim();
            const href = normalizeMarkdownUrl(link.getAttribute('href') || '');

            if (!href) {
                link.replaceWith(document.createTextNode(link.textContent || ''));
                return;
            }

            link.setAttribute('href', href);
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');

            if (detailLinkPattern.test(textValue.toLowerCase())) {
                link.className = 'chatbot-link-btn';
            } else {
                link.classList.add('chatbot-inline-link');
            }
        });

        return container.innerHTML;
    }

    function parseLegacy(text) {
        let html = escapeHtml(text);

        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
            const linkClass = detailLinkPattern.test(String(linkText).trim().toLowerCase())
                ? 'chatbot-link-btn'
                : 'chatbot-inline-link';
            const href = normalizeMarkdownUrl(url);

            if (!href) {
                return linkText;
            }

            return `<a href="${escapeHtml(href)}" class="${linkClass}" target="_blank" rel="noopener noreferrer">${linkText}</a>`;
        });

        html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/(?<!\\)\*([^*\n]+)\*/g, '<em>$1</em>');
        html = html.replace(/^---$/gm, '<hr>');
        html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
        html = html.replace(/^[*-] (.+)$/gm, '<li>$1</li>');
        html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
        html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>');
        html = html.replace(/\n/g, '<br>');
        html = html.replace(/<\/(h[1-6]|ul|ol|pre|blockquote|hr)><br>/g, '</$1>');
        html = html.replace(/<br><(h[1-6]|ul|ol|pre|blockquote)/g, '<$1');
        html = html.replace(/<br><br><br>/g, '<br><br>');
        html = html.replace(/<\/li><br><li>/g, '</li><li>');
        html = html.replace(/<ul><br>/g, '<ul>');
        html = html.replace(/<br><\/ul>/g, '</ul>');

        return html;
    }

    function parse(value) {
        const normalizedText = normalizeText(value);

        if (
            window.marked
            && typeof window.marked.parse === 'function'
            && window.DOMPurify
            && typeof window.DOMPurify.sanitize === 'function'
        ) {
            try {
                return decorate(window.marked.parse(normalizedText, {
                    gfm: true,
                    breaks: true
                }));
            } catch (error) {
                console.warn('Marked parse failed, fallback to legacy parser', error);
            }
        }

        return parseLegacy(normalizedText);
    }

    window.ChatbotMarkdownRenderer = {
        parse,
        parseLegacy,
        decorate,
        normalizeText,
        sanitizeInlineMathForPlainText,
        normalizeMarkdownUrl,
        escapeHtml
    };
})();
</script>
@endonce
