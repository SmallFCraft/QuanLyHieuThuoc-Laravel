# Kiểm tra trình duyệt (browser checks)

Chạy tay, không nằm trong CI. Repo không có JS test runner và không thêm
dependency nào cho việc này — cùng lối với `tests/load/voice-normalize.k6.js`.

## Chuẩn bị

```bash
php artisan serve --port=8123
```

Database phải chạy đúng cấu hình trong `.env` — nếu DB chết, storefront trả 500
ở bảng `sessions` và mọi check dưới đây đỏ vì lý do sai.

## Chạy

```bash
node tests/browser/voice-search.cjs
```

Script tự dò `chromium_headless_shell-*` mới nhất trong `PLAYWRIGHT_BROWSERS_PATH`
(hoặc thư mục mặc định `%LOCALAPPDATA%\ms-playwright`). Máy nào đã cài Playwright
chuẩn thì không cần cấu hình gì thêm.

## Biến môi trường

| Biến | Mặc định | Dùng khi |
|---|---|---|
| `BASE_URL` | `http://localhost:8123` | dev server nằm ở cổng khác |
| `PW_CHROMIUM` | tự dò trong `PLAYWRIGHT_BROWSERS_PATH` | browser binary cài thủ công ở chỗ khác |

## Khi `playwright` không resolve được

Một số máy không có `playwright` nào resolve được (không có `node_modules` cục
bộ). Script thử lần lượt: `playwright-core` cục bộ → `playwright` cục bộ →
`playwright-core` trong npm global root (`npm root -g`). Cả ba đều lỗi thì cài
vào repo:

```bash
npm install --no-save playwright-core
```

`--no-save` để không đổi `package.json` — đây chỉ là harness chạy tay, không vào CI.

## Vì sao mock SpeechRecognition

Web Speech cần mic thật và người nói thật. Các nhánh lỗi — bị từ chối quyền,
`start()` ném, hết 8 giây im lặng, fetch treo — không kiểm được bằng tay một cách
tất định. Mock biến mỗi nhánh thành một lời gọi hàm.

## Trạng thái hiện tại

Cả 30 check đang XANH (chạy tay xác nhận 2026-11-12, các fix thuộc commit
`aaa2dc3` đã vào). Exit code: `0` khi toàn pass, `1` khi có check đỏ.
