<div align="center">

# 🏪 Quản Lý Hiệu Thuốc

**Hệ thống quản lý nhà thuốc trên Laravel** — cửa hàng trực tuyến, trợ lý chatbot AI, sàng lọc sức khỏe có hàng chờ dược sĩ duyệt và bộ công cụ quản trị đầy đủ. Toàn bộ giao diện tiếng Việt.

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.2-777BB4?logo=php&logoColor=white)](#-yêu-cầu-môi-trường)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](#-công-nghệ-sử-dụng)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3-38B2AC?logo=tailwindcss&logoColor=white)](#-công-nghệ-sử-dụng)
[![Vite](https://img.shields.io/badge/Vite-6-646CFF?logo=vite&logoColor=white)](#-công-nghệ-sử-dụng)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-12-3776AB?logo=phpunit&logoColor=white)](#-chạy-kiểm-thử)
[![Tiếng Việt](https://img.shields.io/badge/Giao%20di%E1%BB%87n-Ti%E1%BA%BFng%20Vi%E1%BB%87t-E01E5A?logo=translate&logoColor=white)](#)

**Repository:** [github.com/SmallFCraft/QuanLyHieuThuoc-Laravel](https://github.com/SmallFCraft/QuanLyHieuThuoc-Laravel)
**📄 Báo cáo đồ án:** [Báo cáo đầy đủ (PDF)](docs/Bao-cao-Triage/do-an.pdf) · [Slide thuyết trình (PPTX)](docs/Bao-cao-Triage/do-an-slides.pptx)

</div>

---

## 📖 Mục lục

| | | |
|---|---|---|
| [✨ Tính năng chính](#-tính-năng-chính) | [🚀 Cài đặt từ đầu](#-cài-đặt-từ-đầu) | [⏰ Tác vụ định kỳ](#-tác-vụ-định-kỳ) |
| [🧰 Công nghệ sử dụng](#-công-nghệ-sử-dụng) | [⚙️ Cấu hình môi trường](#️-cấu-hình-môi-trường-env) | [🧪 Chạy kiểm thử](#-chạy-kiểm-thử) |
| [📋 Yêu cầu môi trường](#-yêu-cầu-môi-trường) | [▶️ Chạy ứng dụng](#️-chạy-ứng-dụng) | [🛠️ Xử lý sự cố](#️-xử-lý-sự-cố-thường-gặp) |
| [📄 Báo cáo đồ án](#-báo-cáo-đồ-an) | | |

<br>

> [!TIP]
> **📄 [Báo cáo đầy đủ (PDF)](docs/Bao-cao-Triage/do-an.pdf)** · **📊 [Slide thuyết trình (PPTX)](docs/Bao-cao-Triage/do-an-slides.pptx)**

---

## ✨ Tính năng chính

### 🛒 Cửa hàng trực tuyến
- Danh mục sản phẩm với phân loại, tìm kiếm và gợi ý trực tiếp (`ProductCatalogController`)
- Giỏ hàng lưu theo session, thêm sản phẩm qua AJAX
- Đặt hàng cho khách đã đăng nhập
- Danh sách yêu thích của từng người dùng (bật/tắt, đồng bộ)

### 💊 Động cơ an toàn thuốc (`DrugSafetyService`)
- Trích xuất hoạt chất và kiểm tra tương tác thuốc–thuốc (`DrugInteraction`)
- Kiểm tra chống chỉ định theo hồ sơ sức khỏe khách hàng (`DrugContraindication`)
- Sàng lọc an toàn toàn giỏ hàng trước khi đặt, ghi nhật ký mọi lần vượt qua cảnh báo (`AuditLog`)

### 🤖 Chatbot AI (`ChatbotService` + `AiProviderService`)
- Nhà cung cấp AI tương thích OpenAI với 3 chế độ API: `chat_completions`, `responses`, `anthropic_messages`
- Tự động chuyển sang nhà cung cấp dự phòng khi lỗi xác thực, lỗi phía trên, giới hạn tần suất hoặc timeout
- Đọc ảnh đơn thuốc / hóa đơn qua vision endpoint (`ImageRecognitionService`), trả lời dựa trên nội dung ảnh đã đọc kèm tra cứu danh mục
- Thêm vào giỏ và đặt hàng ngay trong khung chat (`routes/api.php`)
- Xử lý qua hàng đợi: tin nhắn trả job id chỉ sau ~200 ms, trình duyệt tự poll — lời gọi AI dài không chặn request web (`ProcessChatbotMessage`, `AiJob`)
- Giới hạn tần suất, giới hạn lịch sử hội thoại, tự dọn ảnh tải lên

### 🩺 Sàng lọc sức khỏe (`MedicalTriageService`)

> **Luồng xử lý:** triệu chứng từ văn bản tự do → bộ máy luật tất định phân tầng → AI chỉ diễn giải và bổ sung lời khuyên — không bao giờ hạ cấp nguy cơ.

- Nhận diện triệu chứng và dấu hiệu cờ đỏ dựa trên tri thức y khoa lưu trong database (`MedicalSymptom`, `MedicalRedFlag`, `MedicalTriageRule`)
- Wizard nhiều bước: AI trích xuất sự kiện, hỏi làm rõ, viết lại lời khuyên, soạn thảo cho dược sĩ — mỗi điểm nối AI đều hạ cấp về kết quả bộ máy luật khi nhà cung cấp chết (công tắc cắt: `AI_TRIAGE_*`)
- Dấu hiệu cờ đỏ cấp cứu chuyển thẳng tới hướng dẫn cấp cứu
- Chuyển giao tùy chọn sang **hàng chờ duyệt của dược sĩ** (`Pharmacist\TriageQueueController`): nhận ca / trả lời / bản nháp AI / chuyển cấp trên
- Bản tóm tắt kết quả do AI sinh, xử lý trong worker nền (`ProcessTriageBriefing`)
- Email hỏi lại (có chữ ký) khách được tư vấn tự chăm sóc xem triệu chứng đã đỡ chưa (`TriageFollowUpMail`)

### ⏰ Nhắc uống thuốc (`MedicationReminderService`)
- Lịch nhắc uống thuốc theo từng người dùng, gửi đúng hạn kèm email thông báo (`MedicationReminderMail`)

### 🏢 Trang quản trị (`/admin`)
- Dashboard doanh thu, trạng thái đơn hàng, sản phẩm bán chạy (Chart.js)
- Quản lý sản phẩm, danh mục, đơn hàng, tồn kho (theo dõi lô / sắp hết / hết hạn), người dùng
- Xuất Excel cho sản phẩm, đơn hàng, tồn kho, người dùng và báo cáo sàng lọc (`maatwebsite/excel`)
- Trình xem phiên hội thoại và dashboard báo cáo sàng lọc với biểu đồ kết quả/xu hướng
- Ghi nhật ký kiểm toán toàn hệ thống qua `AuditLogMiddleware`

### 🔐 Vai trò & bảo mật
- Ba vai trò: `Administrator`, `Pharmacist`, `User` (`AdminMiddleware`, `PharmacistMiddleware`)
- Middleware bảo mật header toàn cục, giới hạn tần suất cho các endpoint nhạy cảm, mã hóa phiên chat

---

## 🧰 Công nghệ sử dụng

| Tầng | Công nghệ |
|---|---|
| ⚙️ Backend | PHP ^8.2, Laravel ^13.0 |
| 🗄️ Database | SQLite (mặc định; hỗ trợ MySQL qua biến `DB_*`) |
| 📬 Queue & cache | Queue và cache lưu trên database (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`) |
| 🎨 Frontend | Vite, Tailwind CSS, Alpine.js, Bootstrap 5, DataTables, Chart.js, SweetAlert2 |
| 🤖 AI | Nhà cung cấp tương thích OpenAI `/v1` (chat completions, responses, Anthropic messages) |
| 🖼️ Đọc ảnh | Vision của nhà cung cấp AI (không cần OCR riêng) |
| 📤 Xuất file | Maatwebsite Excel |
| 🧪 Kiểm thử | PHPUnit ^12.0 |

---

## 📋 Yêu cầu môi trường

| Thành phần | Ghi chú |
|---|---|
| **PHP ≥ 8.2** | Extension `pdo_sqlite`, `gd` hoặc `imagick`, `fileinfo` |
| **Composer** | Cài dependency PHP |
| **Node.js** | Build tài nguyên Vite |
| **Nhà cung cấp AI** | Tương thích OpenAI có vision — chỉ cần khi dùng tính năng AI |
| **Queue worker** | 🔴 Bắt buộc cho tính năng AI — xem [Chạy ứng dụng](#️-chạy-ứng-dụng) |

---

## 🚀 Cài đặt từ đầu

### Windows (PowerShell)

```powershell
git clone https://github.com/SmallFCraft/QuanLyHieuThuoc-Laravel.git
Set-Location QuanLyHieuThuoc-Laravel
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Force database/database.sqlite
php artisan migrate --seed
npm run build
```

### Linux / macOS (bash)

```bash
git clone https://github.com/SmallFCraft/QuanLyHieuThuoc-Laravel.git
cd QuanLyHieuThuoc-Laravel
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build
```

### Sau khi cài đặt

`migrate --seed` tạo:

- 🩺 **Cơ sở tri thức y khoa** — triệu chứng, dấu hiệu cờ đỏ, quy tắc phân tầng (`MedicalKnowledgeSeeder`)
- 👥 **Tài khoản demo** — Quản trị viên, Dược sĩ và người dùng thường. Thông tin đăng nhập xem tại [database/seeders/UserSeeder.php](database/seeders/UserSeeder.php)

> [!WARNING]
> Danh mục sản phẩm **không** có trong seeder — nó được nạp từ crawler. Sau khi seed, chạy:
>
> ```bash
> php artisan crawl:longchau
> ```
>
> Lệnh này nạp danh mục sản phẩm từ Nhà Thuốc Long Châu vào bảng `products`. Chỉ sau bước này mới đặt được đơn hàng.

---

## ⚙️ Cấu hình môi trường (.env)

Sao chép `.env.example` thành `.env` rồi điều chỉnh. Các biến đáng chú ý:

### Ứng dụng

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Ho_Chi_Minh` | Múi giờ ứng dụng |
| `APP_LOCALE` | `vi` | Ngôn ngữ giao diện (tiếng Việt) |

### Database

SQLite là mặc định (`DB_CONNECTION=sqlite`). Muốn dùng MySQL, bỏ chú thích khối `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`.

### 🤖 Nhà cung cấp AI (chatbot & sàng lọc)

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `AI_API_MODE` | `chat_completions` | `chat_completions` \| `responses` \| `anthropic_messages` |
| `AI_BASE_URL` | — | URL gốc nhà cung cấp (tương thích OpenAI `/v1`) |
| `AI_API_KEY` | — | API key |
| `AI_MODEL` | — | Tên model |
| `AI_MAX_TOKENS` | `4096` | Trần độ dài câu trả lời (bắt buộc với chế độ Anthropic messages) |
| `AI_TIMEOUT` / `AI_CONNECT_TIMEOUT` | `20` / `5` | Ngân sách thời gian tổng / riêng giai đoạn kết nối |

<details>
<summary><strong>🔋 Nhà cung cấp dự phòng (tùy chọn)</strong></summary>

`AI_FALLBACK_API_MODE`, `AI_FALLBACK_BASE_URL`, `AI_FALLBACK_API_KEY`, `AI_FALLBACK_MODEL`, `AI_FALLBACK_TIMEOUT`. Dùng khi nhà cung cấp chính lỗi.

</details>

<details>
<summary><strong>🚨 Công tắc cắt AI của sàng lọc</strong></summary>

Mặc định đều bật `true`; mỗi điểm nối tự hạ về kết quả bộ máy luật khi nhà cung cấp chết. Chỉ tắt khi cần.

| Biến | Ý nghĩa |
|---|---|
| `AI_TRIAGE_FACTS` | Trích xuất sự kiện bằng AI |
| `AI_TRIAGE_CLARIFY` | Câu hỏi làm rõ bằng AI |
| `AI_TRIAGE_ADVICE` | Viết lại lời khuyên (chạy queue, `AI_TRIAGE_ADVICE_TIMEOUT=45`) |
| `AI_TRIAGE_DRAFT` | Bản nháp cho dược sĩ |
| `AI_TRIAGE_BRIEFING` | Tóm tắt trang kết quả (chạy queue, `AI_TRIAGE_BRIEFING_TIMEOUT=90`) |
| `AI_TRIAGE_TIMEOUT` | Ngân sách fail-fast cho các điểm nối chạy trong request (mặc định `30`) |

</details>

<details>
<summary><strong>🔧 Khác</strong></summary>

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `AI_IMAGE_ANSWER` | `true` | AI trả lời câu hỏi đi kèm ảnh tải lên |
| `AI_ANTHROPIC_VERSION` | `2023-06-01` | Header phiên bản API, chỉ dùng cho chế độ `anthropic_messages` |
| `AI_THINKING` | `disabled` | Công tắc extended-thinking cho nhà cung cấp có hỗ trợ |

</details>

---

## ▶️ Chạy ứng dụng

### ⚡ Cách nhanh nhất — một lệnh duy nhất

```bash
composer dev
```

Lệnh này chạy **ba tiến trình song song**: dev server, **queue worker** và Vite. (Script `dev` trong `composer.json` hiện không khởi động Pail.)

### 🔴 Queue worker là bắt buộc cho tính năng AI

Các lời gọi AI (trả lời chatbot, viết lại lời khuyên sàng lọc, tóm tắt kết quả) chạy dưới dạng **job trong hàng đợi** để không bao giờ chặn request web — một lời gọi AI có thể mất 15 giây trở lên, còn `php artisan serve` xử lý mỗi lúc một request.

- ✅ Có worker: `POST /api/chatbot/message` trả về sau ~200 ms kèm job id để trình duyệt poll.
- ⚠️ Không có worker: các đường dự phòng có thể chạy đồng bộ sau một khoảng trễ — một số luồng vẫn trả lời được nhưng chậm hơn và request bị chặn.

### Chạy thủ công từng tiến trình

```bash
php artisan serve
```

```bash
php artisan queue:work --tries=1 --timeout=120
```

> [!NOTE]
> `PHP_CLI_SERVER_WORKERS` không có tác dụng trên Windows (`pcntl_fork` không khả dụng), nên dev server luôn chạy một tiến trình. Cần đồng thời request thật thì dùng Apache/nginx + PHP-FPM hoặc Laravel Octane.

---

## ⏰ Tác vụ định kỳ

Scheduler trong [routes/console.php](routes/console.php) cần một tiến trình chạy nền:

```bash
php artisan schedule:work
```

| Lịch | Lệnh | Mục đích |
|---|---|---|
| ⏱️ Mỗi 15 phút | `reminders:dispatch` | Gửi email nhắc uống thuốc đến hạn |
| 🌙 Hàng ngày 02:00 | `audit:cleanup` | Dọn nhật ký kiểm toán |
| 🌙 Hàng ngày 03:00 | `chat:cleanup` | Xóa tin nhắn chat cũ hơn 30 ngày |
| 🌅 Hàng ngày 09:00 | `triage:follow-up` | Email hỏi lại sau tư vấn tự chăm sóc |
| 🔁 Hàng giờ | `chatbot:cleanup-images` | Xóa ảnh chatbot tải lên cũ hơn 24 giờ |
| 🔁 Hàng giờ | `ai-jobs:cleanup` | Đóng job AI đã chết, xóa payload đã lưu |
| 📅 Chủ nhật 03:00 | `images:cleanup` | Xóa ảnh của sản phẩm đã xóa mềm |
| 📅 Chủ nhật 04:00 | `crawl:longchau` | Làm mới danh mục sản phẩm từ Long Châu |
| 📅 Chủ nhật 05:00 | `voice:build-phonetics` | Dựng lại index phiên âm cho tìm kiếm bằng giọng nói |

Các lệnh gửi email dùng `withoutOverlapping` để một đợt chậm không gửi trùng mail cho bệnh nhân.

### Lệnh chẩn đoán AI

```bash
php artisan chatbot:ai-check --vision
```

Gửi một request thật tới từng nhà cung cấp đã cấu hình (chính và dự phòng); thêm `--vision` để kèm request ảnh. Dùng để kiểm tra cấu hình AI.

---

## 🧪 Chạy kiểm thử

Cấu hình PHPUnit ([phpunit.xml](phpunit.xml)) dùng database SQLite trong bộ nhớ, driver `array` cho queue/session/cache/mail, và khóa cứng biến môi trường AI — test không bao giờ phụ thuộc `.env` máy bạn.

```bash
php artisan test
```

hoặc chạy trực tiếp:

```bash
vendor/bin/phpunit
```

Bộ test: `tests/Unit` (services, helpers, tồn kho) và `tests/Feature` (luồng sàng lọc, hàng đợi chatbot, an toàn thuốc, đặt hàng, hàng chờ dược sĩ, báo cáo admin, xác thực, bảo mật).

---

## 📁 Cấu trúc thư mục

```
app/
├── Console/Commands/        # Lệnh dọn dẹp, nhắc thuốc, chẩn đoán AI
├── Exports/                 # Xuất Excel cho admin (Maatwebsite)
├── Http/Controllers/        # Storefront, Admin/, Auth/, Pharmacist/
├── Http/Middleware/         # AdminMiddleware, PharmacistMiddleware, AuditLogMiddleware, SecurityHeaders
├── Jobs/                    # Job hàng đợi: chatbot, đánh giá/viết lại/tóm tắt sàng lọc
├── Models/                  # 22 model Eloquent (sản phẩm, đơn hàng, sàng lọc, tri thức y khoa…)
└── Services/                # Logic nghiệp vụ: ChatbotService, MedicalTriageService, DrugSafetyService, …
resources/views/             # Blade templates (storefront, admin, widget chatbot)
routes/                      # web.php, api.php, console.php
database/                    # 20 migrations, seeders gồm MedicalKnowledgeSeeder
lang/vi/                     # Bản dịch tiếng Việt
```

---

## 🛠️ Xử lý sự cố thường gặp

### 💥 Trang trắng hoặc lỗi 500 sau khi clone

Thường do thiếu `.env` hoặc chưa tạo key:

```bash
php artisan key:generate
```

### 🗄️ Lỗi `SQLITE_CANTOPEN` hoặc migrate lỗi

File `database/database.sqlite` chưa tồn tại. Tạo rồi migrate lại:

```bash
New-Item -ItemType File -Force database/database.sqlite   # Windows
touch database/database.sqlite                            # Linux/macOS
php artisan migrate --seed
```

### 🤖 Chatbot trả lời mãi không xong, sàng lọc không có tóm tắt AI

Queue worker chưa chạy. AI chạy qua hàng đợi — không có worker thì job nằm im trong bảng `jobs`. Mở terminal thứ hai:

```bash
php artisan queue:work --tries=1 --timeout=120
```

Xác nhận cấu hình AI đúng:

```bash
php artisan chatbot:ai-check --vision
```

### 📦 Danh mục sản phẩm trống, không đặt được đơn

Danh mục không có trong seeder. Nạp từ crawler:

```bash
php artisan crawl:longchau
```

Chỉ ~20% trang thuốc công bố giá — sản phẩm không giá sẽ bị bỏ qua, đó là hành vi chủ đích.

### 🧩 Thiếu extension PHP

Nếu `composer install` báo lỗi `pdo_sqlite`, `gd` hoặc `fileinfo` — bật extension đó trong `php.ini` (bỏ chú thích dòng `extension=pdo_sqlite`, `extension=gd`, `extension=fileinfo`) rồi chạy lại.

### 🎙️ Tìm kiếm bằng giọng nói không nhận diện tốt

Index phiên âm chưa dựng hoặc catalog vừa thay đổi:

```bash
php artisan voice:build-phonetics
```

---

## 📄 Báo cáo đồ án

> [!NOTE]
> Đồ án học phần **AIP202 — Trí tuệ Nhân tạo** (2 tín chỉ), Khoa Công nghệ Thông tin, ĐH Kiến trúc Đà Nẵng. Đề tài: *"Xây dựng hệ thống hỗ trợ sàng lọc và phân tầng nguy cơ y tế kết hợp trí tuệ nhân tạo"*. Sinh viên: Phạm Trường Huy, Lê Văn Trung (23CT5). GVHD: TS. Đỗ Phúc Hảo.

Báo cáo đầy đủ: **[docs/Bao-cao-Triage/do-an.pdf](docs/Bao-cao-Triage/do-an.pdf)** — kiến trúc Hybrid AI: bộ máy luật tất định (31 triệu chứng, 22 dấu hiệu cờ đỏ theo Manchester, 44 luật) quyết định, LLM chỉ trích xuất sự kiện và sinh nội dung diễn giải. Thực nghiệm 26 ca bệnh: không ca cấp cứu nào bị xếp xuống nhóm tự chăm sóc, xử trí an toàn lâm sàng 88,5%. Nguồn LaTeX tại [docs/Bao-cao-Triage/](docs/Bao-cao-Triage/).

Slide thuyết trình: **[docs/Bao-cao-Triage/do-an-slides.pptx](docs/Bao-cao-Triage/do-an-slides.pptx)**.

---

<div align="center">

**Quản Lý Hiệu Thuốc** · Laravel 13 · ĐH Kiến trúc Đà Nẵng

</div>
