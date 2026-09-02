# Browser checks

Chạy tay, không nằm trong CI. Repo không có JS test runner và không thêm
dependency nào cho việc này — cùng lối với `tests/load/voice-normalize.k6.js`.

## Chuẩn bị

```bash
php artisan serve --port=8123
```

MySQL phải chạy (Laragon). Nếu 3306 chết, storefront trả 500 ở bảng `sessions`
và mọi check dưới đây đỏ vì lý do sai.

## Chạy

```bash
NODE_PATH="E:/Apps/npm-global/node_modules" node tests/browser/voice-search.cjs
```

## Biến môi trường

| Biến | Mặc định | Dùng khi |
|---|---|---|
| `BASE_URL` | `http://localhost:8123` | dev server nằm ở cổng khác |
| `PW_CHROMIUM` | `E:/Apps/Browsers/Playwright/chromium_headless_shell-1237/chrome-headless-shell-win64/chrome-headless-shell.exe` | browser binary nằm chỗ khác |

`NODE_PATH` là bắt buộc trên máy này vì repo không có `node_modules` cục bộ và
`playwright` không resolve được từ đâu cả, chỉ `playwright-core` ở global root
(`E:\Apps\npm-global\node_modules`) mới resolve — đo 2026-09-02. Repo nào có
`playwright` cài cục bộ thì bỏ `NODE_PATH` đi, harness tự fallback sang nó.
`executablePath` cũng phải chỉ tường minh: `PLAYWRIGHT_BROWSERS_PATH` đang giữ
`chromium-1237` còn `playwright-core` 1.60.0 đi tìm `-1223`.

## Vì sao mock SpeechRecognition

Web Speech cần mic thật và người nói thật. Các nhánh lỗi — bị từ chối quyền,
`start()` ném, hết 8 giây im lặng, fetch treo — không kiểm được bằng tay một cách
tất định. Mock biến mỗi nhánh thành một lời gọi hàm.

## Trạng thái hiện tại

Tám check (F1, F2, F4, F5, F6, F7, F10, F14) đang ĐỎ có ý: chúng tái hiện 8 trong
15 lỗi máy trạng thái phía client. Dòng `process.exit` cuối file bị đảo ngược nên
exit code là 0 khi có check đỏ — Task 3 lật lại sau khi các fix vào.
