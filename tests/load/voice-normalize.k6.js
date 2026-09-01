/**
 * Test tải cho POST /api/voice/normalize.
 *
 * CHẠY TAY, không nằm trong CI: k6 là binary ngoài, và plan này không thêm
 * dependency nào vào repo.
 *
 * Điều kiện bắt buộc: phải chạy sau nginx + php-fpm. `php artisan serve` trên
 * Windows chỉ có MỘT process (pcntl_fork không có trên nền tảng này — đã đo:
 * 4 request song song cho tỉ lệ 2,9x), nên đo trên đó là đo giới hạn của web
 * server chứ không phải của tính năng.
 *
 *   k6 run -e BASE_URL=http://127.0.0.1:8080 tests/load/voice-normalize.k6.js
 *
 * Trước khi chạy: php artisan voice:build-phonetics
 */
import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8080';

const throttled = new Rate('throttled');
const bySource = {
    exact: new Rate('source_exact'),
    phonetic: new Rate('source_phonetic'),
    ai: new Rate('source_ai'),
    raw: new Rate('source_raw'),
};
const latency = new Trend('normalize_ms', true);

/** 85% câu khớp được ở tầng phiên âm, 15% câu lạ — tỉ lệ trượt ước tính của spec. */
const MATCHED = [
    'thuấc hạ sốc',
    'mem vi xinh',
    'nước muối xinh lý',
    'kem chóng nắng',
    'vi ta min xê',
    'khẩu chang y tế',
];

const UNMATCHED = [
    'bơ bơ rin gì đó',
    'cái thuốc màu xanh hôm trước',
    'thuốc gì cho đỡ mệt',
];
export const options = {
    scenarios: {
        voice: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '15s', target: 200 },
                { duration: '15s', target: 1000 },
                { duration: '30s', target: 1000 },
                { duration: '10s', target: 0 },
            ],
        },
    },
    thresholds: {
        // Ngưỡng p95 nới hơn mục tiêu 150ms của §7.1 vì con số này bao cả thời gian
        // mạng và web server, không chỉ thời gian trong endpoint.
        'normalize_ms{expected_response:true}': ['p(95)<400'],
        http_req_failed: ['rate<0.01'],
    },
};

export default function () {
    const useMatched = Math.random() < 0.85;
    const pool = useMatched ? MATCHED : UNMATCHED;
    const spoken = pool[Math.floor(Math.random() * pool.length)];

    const response = http.post(
        `${BASE_URL}/api/voice/normalize`,
        JSON.stringify({ alternatives: [spoken] }),
        { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }
    );

    latency.add(response.timings.duration);
    throttled.add(response.status === 429);

    // 429 là hành vi đúng dưới 1.000 VU, không phải lỗi: throttle là 30/phút mỗi IP,
    // và k6 chạy từ một IP. Con số đáng đọc là tỉ lệ 5xx và phân bố `source`.
    if (response.status === 200) {
        const source = response.json('source');

        Object.keys(bySource).forEach((key) => bySource[key].add(source === key));

        check(response, {
            'trả về text không rỗng': (r) => (r.json('text') || '').length > 0,
            'heard luôn có': (r) => (r.json('heard') || '').length > 0,
        });
    }

    check(response, { 'không phải 5xx': (r) => r.status < 500 });
}
