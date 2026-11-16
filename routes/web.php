<?php

use App\Http\Controllers\HoSoController;
use App\Http\Controllers\NhacUongThuocController;
use App\Http\Controllers\SanPhamController;
use App\Http\Controllers\TimKiemGiongNoiController;
use App\Http\Controllers\GioHangController;
use App\Http\Controllers\ThanhToanController;
use App\Http\Controllers\YeuThichController;
use App\Http\Controllers\SangLocController;
use App\Http\Controllers\HoSoSucKhoeController;
use App\Http\Controllers\TrangChuController;
use App\Http\Controllers\LienHeController;
use App\Http\Controllers\Admin\BangDieuKhienController;
use App\Http\Controllers\Admin\DonHangController as AdminDonHangController;
use App\Http\Controllers\Admin\SanPhamController as AdminSanPhamController;
use App\Http\Controllers\Admin\DanhMucController as AdminDanhMucController;
use App\Http\Controllers\Admin\TonKhoController as AdminTonKhoController;
use App\Http\Controllers\Admin\NguoiDungController as AdminNguoiDungController;
use App\Http\Controllers\Admin\PhienChatController as AdminPhienChatController;
use App\Http\Controllers\Admin\BaoCaoSangLocController as AdminBaoCaoSangLocController;
use App\Http\Controllers\DuocSi\HangChoSangLocController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', [TrangChuController::class, 'index'])->name('trang-chu');

// Contact routes
Route::get('/contact', [LienHeController::class, 'index'])->name('lien-he');
Route::post('/contact/submit', [LienHeController::class, 'submit'])
    ->middleware('throttle:5,1')
    ->name('lien-he.gui');

// Product catalog routes
Route::get('/products', [SanPhamController::class, 'index'])->name('san-pham.index');
Route::get('/products/{MaSP}', [SanPhamController::class, 'show'])->name('san-pham.show');
Route::get('/products/category/{MaDM}', [SanPhamController::class, 'byCategory'])->name('san-pham.danh-muc');
Route::get('/search', [SanPhamController::class, 'search'])->name('san-pham.tim-kiem');
// Own bucket keyed on the route: the autocomplete fires on every keystroke past a
// 300ms debounce, so it needs a ceiling that browsing the catalog does not share.
Route::get('/api/search/suggestions', [SanPhamController::class, 'suggestions'])
    ->middleware('throttle:60,1,search-suggestions:')
    ->name('san-pham.goi-y');

// Chuan hoa chuoi Web Speech nghe duoc. POST vi no nhan mot mang alternative, va
// nam trong nhom `web` de dung session/CSRF nhu phan con lai cua storefront.
// Bucket throttle rieng: mot luot noi mat it nhat 2 giay nen 30/phut la du cho
// nguoi that, ma voice khong an vao han muc cua autocomplete.
Route::post('/api/voice/normalize', [TimKiemGiongNoiController::class, 'normalize'])
    ->middleware('throttle:30,1,voice-normalize:')
    ->name('tim-kiem-giong-noi.chuan-hoa');

// Shopping cart routes
Route::get('/cart', [GioHangController::class, 'index'])->name('gio-hang.index');
Route::post('/cart/add', [GioHangController::class, 'add'])->name('gio-hang.them');
Route::match(['post', 'put'], '/cart/update/{MaSP}', [GioHangController::class, 'update'])->name('gio-hang.cap-nhat');
Route::match(['post', 'delete'], '/cart/remove/{MaSP}', [GioHangController::class, 'remove'])->name('gio-hang.xoa');

// Checkout routes (requires authentication)
Route::middleware('auth')->group(function () {
    Route::get('/checkout', [ThanhToanController::class, 'index'])->name('thanh-toan.index');
    Route::post('/checkout/process', [ThanhToanController::class, 'process'])->name('thanh-toan.xu-ly');
    Route::get('/checkout/success/{MaDH}', [ThanhToanController::class, 'success'])->name('thanh-toan.thanh-cong');
});

// Health triage routes (requires authentication)
Route::middleware('auth')->group(function () {
    Route::get('/health-check', [SangLocController::class, 'index'])->name('sang-loc.kiem-tra');
    Route::post('/health-check/answer', [SangLocController::class, 'answer'])
        ->middleware('throttle:20,1')
        ->name('sang-loc.tra-loi');
    Route::post('/health-check/back', [SangLocController::class, 'back'])->name('sang-loc.quay-lai');
    // One bounded AI follow-up round, offered on the wizard page after step 5.
    Route::post('/health-check/clarify', [SangLocController::class, 'clarify'])
        ->middleware('throttle:20,1')
        ->name('sang-loc.lam-ro');
    Route::post('/health-check/restart', [SangLocController::class, 'restart'])->name('sang-loc.bat-dau-lai');

    // Stop repeating the scope notice as a popup for the rest of the calendar day.
    // Decided server-side so the popup and its dismiss both work with JavaScript off.
    //
    // Own bucket, not a bare `throttle:10,1`: a numeric throttle keys on sha1(user id)
    // with no route component, so every numeric-throttled route in the app shares one
    // per-user counter. Measured — 40 hits on health.decision (its own limit is 120/min,
    // and the browser polls it every 1.5s while a worker decides) then 429 here, which
    // would mean the daily flag never gets written and the popup nags forever.
    Route::post('/health-check/disclaimer', [SangLocController::class, 'ackDisclaimer'])
        ->middleware('throttle:10,1,disclaimer-ack:')
        ->name('sang-loc.disclaimer.xac-nhan');
    // Progress of the queued outcome decision. Polled every second or two while the
    // patient waits, so it gets its own, higher limit.
    Route::get('/health-check/decision/{uuid}', [SangLocController::class, 'decision'])
        ->middleware('throttle:120,1')
        ->name('sang-loc.quyet-dinh');
    Route::get('/health-check/history', [SangLocController::class, 'history'])->name('sang-loc.lich-su');
    Route::get('/health-check/result/{MaSL}', [SangLocController::class, 'result'])
        ->whereNumber('MaSL')
        ->name('sang-loc.ket-qua');
    Route::get('/health-check/result/{MaSL}/briefing-pane', [SangLocController::class, 'briefingPane'])
        ->middleware('throttle:60,1')
        ->whereNumber('MaSL')
        ->name('sang-loc.ket-qua.briefing-pane');
    // Opt-in side channel: the AI/rule answer is the primary result, and the queue is
    // only entered when the customer asks for a human.
    Route::post('/health-check/result/{MaSL}/pharmacist', [SangLocController::class, 'requestPharmacist'])
        ->middleware('throttle:10,1')
        ->whereNumber('MaSL')
        ->name('sang-loc.xin-duoc-si');

    // Follow-up from the email. The GET link only renders a confirmation page —
    // it is signed and changes nothing, so a mail scanner or prefetcher that opens
    // it cannot record an answer. The answer itself is a CSRF-protected POST.
    Route::get('/health-check/{MaSL}/follow-up', [NhacUongThuocController::class, 'followUpConfirm'])
        ->middleware('signed')
        ->whereNumber('MaSL')
        ->name('sang-loc.theo-doi.xac-nhan');
    Route::post('/health-check/{MaSL}/follow-up', [NhacUongThuocController::class, 'followUpResponse'])
        ->middleware('throttle:20,1')
        ->whereNumber('MaSL')
        ->name('sang-loc.theo-doi.tra-loi');

    Route::get('/reminders', [NhacUongThuocController::class, 'index'])->name('nhac-uong.index');
    Route::post('/reminders/{MaNN}/cancel', [NhacUongThuocController::class, 'cancel'])
        ->whereNumber('MaNN')
        ->name('nhac-uong.huy');
});

// Profile routes
Route::middleware('auth')->group(function () {
    Route::get('/profile', [HoSoController::class, 'edit'])->name('ho-so.edit');
    Route::patch('/profile', [HoSoController::class, 'update'])->name('ho-so.update');
    Route::delete('/profile', [HoSoController::class, 'destroy'])->name('ho-so.destroy');

    // throttle: moi luot tai anh la mot lan giai ma anh tren CPU, nen gioi han nhip.
    Route::post('/profile/avatar', [HoSoController::class, 'updateAvatar'])
        ->middleware('throttle:12,1')
        ->name('ho-so.anh-dai-dien.update');
    Route::delete('/profile/avatar', [HoSoController::class, 'destroyAvatar'])->name('ho-so.anh-dai-dien.destroy');

    Route::put('/profile/health', [HoSoSucKhoeController::class, 'update'])->name('ho-so.suc-khoe.update');
    Route::delete('/profile/health', [HoSoSucKhoeController::class, 'destroy'])->name('ho-so.suc-khoe.destroy');

    Route::get('/favorites/ids', [YeuThichController::class, 'ids'])->name('yeu-thich.ids');
    Route::get('/favorites/section', [YeuThichController::class, 'section'])->name('yeu-thich.section');
    Route::post('/favorites/sync', [YeuThichController::class, 'sync'])->name('yeu-thich.sync');
    Route::post('/favorites/{product}/toggle', [YeuThichController::class, 'toggle'])->name('yeu-thich.toggle');
    Route::delete('/favorites/{product}', [YeuThichController::class, 'destroy'])->name('yeu-thich.destroy');
});

// Admin routes
// `audit` records every admin POST/PUT/PATCH/DELETE at the HTTP level. Model-level
// auditing (Traits\Auditable) only covers Product, ProductBatch, Order and
// ChatbotImage, so without this Category and User changes leave no trail.
Route::middleware(['auth', 'admin', 'audit'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [BangDieuKhienController::class, 'index'])->name('bang-dieu-khien');
    Route::get('/dashboard/revenue', [BangDieuKhienController::class, 'revenueChart'])->name('bang-dieu-khien.doanh-thu');
    Route::get('/dashboard/order-status', [BangDieuKhienController::class, 'orderStatusChart'])->name('bang-dieu-khien.trang-thai-don');
    Route::get('/dashboard/top-products', [BangDieuKhienController::class, 'topProductsChart'])->name('bang-dieu-khien.san-pham-ban-chay');

    // Products management
    Route::get('/products/data', [AdminSanPhamController::class, 'data'])->name('san-pham.data');
    Route::get('/products/export', [AdminSanPhamController::class, 'export'])->name('san-pham.export');
    Route::resource('products', AdminSanPhamController::class)->names('san-pham');

    // Categories management
    Route::resource('categories', AdminDanhMucController::class)->except(['show'])->names('danh-muc');

    // Orders management
    Route::get('/orders', [AdminDonHangController::class, 'index'])->name('don-hang.index');
    Route::get('/orders/export', [AdminDonHangController::class, 'export'])->name('don-hang.export');
    Route::get('/orders/{MaDH}', [AdminDonHangController::class, 'show'])->name('don-hang.show');
    Route::patch('/orders/{MaDH}/status', [AdminDonHangController::class, 'updateStatus'])->name('don-hang.cap-nhat-trang-thai');
    Route::post('/orders/{MaDH}/cancel', [AdminDonHangController::class, 'cancel'])->name('don-hang.huy');

    // Inventory management
    Route::get('/inventory', [AdminTonKhoController::class, 'index'])->name('ton-kho.index');
    Route::get('/inventory/export', [AdminTonKhoController::class, 'export'])->name('ton-kho.export');
    Route::get('/inventory/low-stock', [AdminTonKhoController::class, 'lowStock'])->name('ton-kho.sap-het');
    Route::get('/inventory/expiring', [AdminTonKhoController::class, 'expiring'])->name('ton-kho.sap-het-han');
    Route::post('/inventory/adjust', [AdminTonKhoController::class, 'adjust'])->name('ton-kho.dieu-chinh');

    // Chat session lookup
    Route::get('/chat-sessions', [AdminPhienChatController::class, 'index'])->name('phien-chat.index');
    Route::get('/chat-sessions/{sessionId}/images/{image}', [AdminPhienChatController::class, 'image'])
        ->whereUuid('sessionId')
        ->whereNumber('image')
        ->name('phien-chat.anh.show');
    Route::get('/chat-sessions/{sessionId}', [AdminPhienChatController::class, 'show'])
        ->whereUuid('sessionId')
        ->name('phien-chat.show');
    // Triage report — static segments must precede {MaSL} or they get captured by it
    Route::get('/triage-reports', [AdminBaoCaoSangLocController::class, 'index'])->name('bao-cao-sang-loc.index');
    Route::get('/triage-reports/export', [AdminBaoCaoSangLocController::class, 'export'])->name('bao-cao-sang-loc.export');
    Route::get('/triage-reports/chart/outcome', [AdminBaoCaoSangLocController::class, 'outcomeChart'])->name('bao-cao-sang-loc.outcomeChart');
    Route::get('/triage-reports/chart/trend', [AdminBaoCaoSangLocController::class, 'trendChart'])->name('bao-cao-sang-loc.trendChart');
    Route::get('/triage-reports/{MaSL}', [AdminBaoCaoSangLocController::class, 'show'])
        ->whereNumber('MaSL')
        ->name('bao-cao-sang-loc.show');

    // Users management
    Route::get('/users', [AdminNguoiDungController::class, 'index'])->name('nguoi-dung.index');
    Route::get('/users/export', [AdminNguoiDungController::class, 'export'])->name('nguoi-dung.export');
    Route::post('/users', [AdminNguoiDungController::class, 'store'])->name('nguoi-dung.store');
    Route::put('/users/{MaKH}', [AdminNguoiDungController::class, 'update'])->name('nguoi-dung.update');
    Route::patch('/users/{MaKH}/status', [AdminNguoiDungController::class, 'updateStatus'])->name('nguoi-dung.cap-nhat-trang-thai');
});

/*
|--------------------------------------------------------------------------
| Pharmacist Routes (Triage Review Queue)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'pharmacist'])->prefix('pharmacist')->name('duoc-si.')->group(function () {
    Route::get('/triage-queue', [HangChoSangLocController::class, 'index'])->name('sang-loc.index');
    Route::get('/triage-queue/{MaSL}', [HangChoSangLocController::class, 'show'])
        ->whereNumber('MaSL')
        ->name('sang-loc.show');
    Route::post('/triage-queue/{MaSL}/claim', [HangChoSangLocController::class, 'claim'])
        ->whereNumber('MaSL')
        ->name('sang-loc.claim');
    Route::post('/triage-queue/{MaSL}/reply', [HangChoSangLocController::class, 'reply'])
        ->whereNumber('MaSL')
        ->name('sang-loc.reply');
    // POST, not GET: the draft costs a provider call, and GET show() writes an audit
    // row on every open. throttle so a held-down button cannot burn the AI budget.
    Route::post('/triage-queue/{MaSL}/draft', [HangChoSangLocController::class, 'draft'])
        ->middleware('throttle:10,1')
        ->whereNumber('MaSL')
        ->name('sang-loc.draft');
    Route::post('/triage-queue/{MaSL}/escalate', [HangChoSangLocController::class, 'escalate'])
        ->whereNumber('MaSL')
        ->name('sang-loc.escalate');
});

require __DIR__.'/auth.php';
