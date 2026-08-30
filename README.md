# Quản Lý Hiệu Thuốc (Pharmacy Management System)

A pharmacy management web application built with Laravel, featuring an online storefront, an AI-powered chatbot assistant, a medication health-triage wizard with pharmacist review, and full admin back-office tooling. The application is entirely in Vietnamese (`APP_LOCALE=vi`).

**Repository:** https://github.com/SmallFCraft/QuanLyHieuThuoc-Laravel

## Key Features

### Storefront
- Product catalog with categories, search, and live search suggestions (`ProductCatalogController`)
- Shopping cart with session-based storage and AJAX add-to-cart
- Checkout and order placement for authenticated customers
- Product favorites (toggle, sync, per-user lists)

### Drug Safety Engine (`DrugSafetyService`)
- Ingredient extraction and drug–drug interaction checks (`DrugInteraction` model)
- Contraindication checks against the customer's health profile (`DrugContraindication`)
- Cart-wide safety screening before checkout, with logged overrides (`AuditLog`)

### AI Chatbot (`ChatbotService` + `AiProviderService`)
- Generic OpenAI-compatible AI provider with three API modes: `chat_completions`, `responses`, and `anthropic_messages`
- Automatic failover to an optional secondary provider on auth, upstream, rate-limit, or timeout errors
- Prescription / receipt image upload read by the AI provider's vision endpoint (`ImageRecognitionService`), answers grounded on what the model read plus catalog lookup
- Chat-embedded cart operations and order placement (`routes/api.php`)
- Queued processing: messages return a job id in ~200 ms and the browser polls, so long AI calls never block a web request (`ProcessChatbotMessage`, `AiJob`)
- Rate limiting, session history limits, and automatic image cleanup

### Health Triage Wizard ("Sàng lọc") (`MedicalTriageService`)
- Symptom and red-flag detection from free text, backed by database-driven medical knowledge (`MedicalSymptom`, `MedicalRedFlag`, `MedicalTriageRule`)
- Multi-step wizard with AI-assisted fact extraction, clarification rounds, advice rewriting, and pharmacist drafts — each AI seam degrades to the deterministic rule-engine result when the provider is unavailable (kill switches: `AI_TRIAGE_*` env flags)
- Emergency red flags route straight to emergency guidance
- Opt-in handoff to a **pharmacist review queue** (`Pharmacist\TriageQueueController`) with claim / reply / AI draft / escalate actions
- AI-generated result-page briefing, processed in a background worker (`ProcessTriageBriefing`)
- Signed follow-up emails asking self-care patients whether symptoms improved (`TriageFollowUpMail`)

### Medication Reminders (`MedicationReminderService`)
- Per-user medication reminder schedules with due-date dispatch and email notifications (`MedicationReminderMail`)

### Admin Back Office (`/admin`)
- Dashboard with revenue, order-status, and top-product charts (Chart.js)
- Products, categories, orders, inventory (batch/low-stock/expiry tracking), and user management
- Excel exports for products, orders, inventory, users, and triage reports (`maatwebsite/excel`)
- Chat session inspector and triage report dashboard with outcome/trend charts
- Full audit logging via `AuditLogMiddleware`

### Roles & Security
- Three roles: `Administrator`, `Pharmacist`, `User` (`AdminMiddleware`, `PharmacistMiddleware`)
- Global security headers middleware, throttled sensitive endpoints, encrypted chat sessions

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP ^8.2, Laravel ^13.0 |
| Database | SQLite (default; MySQL supported via `DB_*` env vars) |
| Queue & cache | Database-backed queue and cache (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`) |
| Frontend | Vite, Tailwind CSS, Alpine.js, Bootstrap 5, DataTables, Chart.js, SweetAlert2 |
| AI | OpenAI-compatible `/v1` providers (chat completions, responses, Anthropic messages modes) |
| Image reading | AI provider vision (no separate OCR engine) |
| Images | Intervention Image |
| Exports | Maatwebsite Excel |
| Testing | PHPUnit ^12.0 |

## Requirements

- PHP >= 8.2 with `pdo_sqlite`, `gd`/`imagick` (image processing), and `fileinfo`
- Composer
- Node.js (Vite asset build)
- An OpenAI-compatible AI provider with vision support (chatbot, triage, image reading)
- A queue worker (required for AI features — see below)

## Installation

```bash
git clone https://github.com/SmallFCraft/QuanLyHieuThuoc-Laravel.git
cd QuanLyHieuThuoc-Laravel
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

Seeding creates the medical knowledge base, sample categories/products/orders, and demo accounts (Administrator, Pharmacist, and regular Users — see `database/seeders/UserSeeder.php` for the development credentials).

### Environment Configuration

Copy `.env.example` to `.env` and adjust. The notable variables:

**Application**

| Variable | Default | Purpose |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Ho_Chi_Minh` | Application timezone |
| `APP_LOCALE` | `vi` | UI language (Vietnamese) |

**Database** — SQLite is the default (`DB_CONNECTION=sqlite`). Uncomment the `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` block to use MySQL.

**AI provider (chatbot & triage)**

| Variable | Default | Purpose |
|---|---|---|
| `AI_API_MODE` | `chat_completions` | `chat_completions` \| `responses` \| `anthropic_messages` |
| `AI_BASE_URL` | — | Provider base URL (OpenAI-compatible `/v1`) |
| `AI_API_KEY` | — | Provider API key |
| `AI_MODEL` | — | Model name |
| `AI_MAX_TOKENS` | `4096` | Answer-length ceiling (required by Anthropic messages mode) |
| `AI_TIMEOUT` / `AI_CONNECT_TIMEOUT` | `20` / `5` | Request budgets |

**Optional fallback provider** — `AI_FALLBACK_API_MODE`, `AI_FALLBACK_BASE_URL`, `AI_FALLBACK_API_KEY`, `AI_FALLBACK_MODEL`, `AI_FALLBACK_TIMEOUT`. Used when the primary provider fails.

**Triage AI seams (kill switches)** — all default `true`; each degrades to the rule-engine result when the provider is down:

| Variable | Purpose |
|---|---|
| `AI_TRIAGE_FACTS` | AI fact extraction |
| `AI_TRIAGE_CLARIFY` | AI clarification questions |
| `AI_TRIAGE_ADVICE` | AI advice rewrite (queued, `AI_TRIAGE_ADVICE_TIMEOUT=45`) |
| `AI_TRIAGE_DRAFT` | AI pharmacist draft |
| `AI_TRIAGE_BRIEFING` | AI result-page briefing (queued, `AI_TRIAGE_BRIEFING_TIMEOUT=90`) |
| `AI_TRIAGE_TIMEOUT` | Fail-fast budget for in-request seams (default `30`) |

**Other**

| Variable | Default | Purpose |
|---|---|---|
| `AI_IMAGE_ANSWER` | `true` | AI answers questions about uploaded images |
| `AI_ANTHROPIC_VERSION` | `2023-06-01` | API version header, `anthropic_messages` mode only |
| `AI_THINKING` | `disabled` | Extended-thinking toggle for providers that support it |

## Running the Development Server

The fastest way to run everything:

```bash
composer dev
```

That starts four processes concurrently: the dev server, a **queue worker**, the log tailer (Pail), and Vite.

**The queue worker is not optional.** AI calls (chatbot replies, triage advice rewriting, triage briefings) run as queued jobs so they never hold a web request — a single AI call can take 15+ seconds, and `php artisan serve` handles one request at a time. With the worker running, `POST /api/chatbot/message` returns in ~200 ms with a job id the browser polls. Without a worker the browser retries synchronously after ~12 seconds, so the chatbot still answers — just slowly, and the site is blocked while it does.

To run the two processes by hand:

```bash
php artisan serve
```

```bash
php artisan queue:work --tries=1 --timeout=120
```

Note: `PHP_CLI_SERVER_WORKERS` has no effect on Windows (`pcntl_fork` is unavailable), so the dev server stays single-process there. For real request concurrency use Apache/nginx + PHP-FPM or Laravel Octane.

## Scheduled Tasks

The scheduler in [routes/console.php](routes/console.php) requires a cron entry (or a background scheduler process):

```bash
php artisan schedule:work
```

| Schedule | Command | Purpose |
|---|---|---|
| Every 15 min | `reminders:dispatch` | Send due medication-reminder emails |
| Daily 02:00 | `audit:cleanup {--days=365}` | Prune audit logs |
| Daily 03:00 | `chat:cleanup` | Delete chat messages older than 30 days |
| Daily 09:00 | `triage:follow-up` | Follow-up emails after self-care advice |
| Hourly | `chatbot:cleanup-images` | Delete chatbot uploads older than 24 h |
| Hourly | `ai-jobs:cleanup` | Close dead AI jobs, drop stored payloads |
| Weekly (Sun 03:00) | `images:cleanup {--days=30}` | Remove images of soft-deleted products |

Reminder and follow-up dispatches use `withoutOverlapping` so a slow batch cannot mail a patient twice.

### Diagnostic Command

```bash
php artisan chatbot:ai-check {--vision}
```

Sends a live request to every configured provider (primary and fallback); `--vision` also sends an image request. Use it to verify provider configuration.

## Running the Tests

The PHPUnit configuration ([phpunit.xml](phpunit.xml)) uses an in-memory SQLite database, the `array` queue/session/cache/mail drivers, and pins the AI provider env so tests never depend on your local `.env`.

```bash
php artisan test
```

or directly:

```bash
vendor/bin/phpunit
```

Suites: `tests/Unit` (services, helpers, inventory) and `tests/Feature` (triage flows, chatbot queueing, drug safety, checkout, pharmacist queue, admin reports, auth, security).

## Project Structure Highlights

```
app/
├── Console/Commands/        # Cleanup, reminder, and AI diagnostics commands
├── Exports/                 # Maatwebsite Excel exports (admin)
├── Http/Controllers/        # Storefront, Admin/, Auth/, Pharmacist/
├── Http/Middleware/         # AdminMiddleware, PharmacistMiddleware, AuditLogMiddleware, SecurityHeaders
├── Jobs/                    # Queued chatbot message, triage evaluation/advice/briefing
├── Models/                  # 20 Eloquent models (products, orders, triage, medical knowledge…)
└── Services/                # Business logic: ChatbotService, MedicalTriageService, DrugSafetyService, …
resources/views/             # Blade templates (storefront, admin, chatbot widget)
routes/                      # web.php, api.php, console.php
database/                    # 17 migrations, seeders incl. MedicalKnowledgeSeeder
lang/vi/                     # Vietnamese translations
Diagram/                     # BFD / DFD / ERD diagrams (draw.io, PNG)
```
