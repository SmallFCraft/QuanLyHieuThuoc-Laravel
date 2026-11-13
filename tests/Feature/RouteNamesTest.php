<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Recovery characterization: the production rename landed before this test, so its
 * original RED state cannot be observed without reverting correct route definitions.
 */
class RouteNamesTest extends TestCase
{
    private const TEN_ROUTE_NGHIEP_VU_CU = [
        'admin.categories.create',
        'admin.categories.destroy',
        'admin.categories.edit',
        'admin.categories.index',
        'admin.categories.store',
        'admin.categories.update',
        'admin.chatSessions.images.show',
        'admin.chatSessions.index',
        'admin.chatSessions.show',
        'admin.dashboard',
        'admin.dashboard.orderStatus',
        'admin.dashboard.revenue',
        'admin.dashboard.topProducts',
        'admin.inventory.adjust',
        'admin.inventory.expiring',
        'admin.inventory.export',
        'admin.inventory.index',
        'admin.inventory.lowStock',
        'admin.orders.cancel',
        'admin.orders.export',
        'admin.orders.index',
        'admin.orders.show',
        'admin.orders.updateStatus',
        'admin.products.create',
        'admin.products.data',
        'admin.products.destroy',
        'admin.products.edit',
        'admin.products.export',
        'admin.products.index',
        'admin.products.show',
        'admin.products.store',
        'admin.products.update',
        'admin.triageReports.export',
        'admin.triageReports.index',
        'admin.triageReports.outcomeChart',
        'admin.triageReports.show',
        'admin.triageReports.trendChart',
        'admin.users.export',
        'admin.users.index',
        'admin.users.store',
        'admin.users.update',
        'admin.users.updateStatus',
        'ai-jobs.cancel',
        'ai-jobs.poll',
        'cart.add',
        'cart.index',
        'cart.remove',
        'cart.update',
        'chatbot.images.show',
        'checkout.index',
        'checkout.process',
        'checkout.success',
        'contact',
        'contact.submit',
        'favorites.destroy',
        'favorites.ids',
        'favorites.section',
        'favorites.sync',
        'favorites.toggle',
        'health.answer',
        'health.back',
        'health.check',
        'health.clarify',
        'health.decision',
        'health.disclaimer.ack',
        'health.followup',
        'health.followup.confirm',
        'health.history',
        'health.requestPharmacist',
        'health.restart',
        'health.result',
        'health.result.briefing-pane',
        'home',
        'pharmacist.triage.claim',
        'pharmacist.triage.draft',
        'pharmacist.triage.escalate',
        'pharmacist.triage.index',
        'pharmacist.triage.reply',
        'pharmacist.triage.show',
        'products.category',
        'products.index',
        'products.search',
        'products.show',
        'products.suggestions',
        'profile.avatar.destroy',
        'profile.avatar.update',
        'profile.destroy',
        'profile.edit',
        'profile.health.destroy',
        'profile.health.update',
        'profile.update',
        'reminders.cancel',
        'reminders.index',
        'voice.normalize',
    ];

    private const HOP_DONG_ROUTE_NGHIEP_VU = [
        'admin.bang-dieu-khien' => ['admin/dashboard', []],
        'admin.bang-dieu-khien.doanh-thu' => ['admin/dashboard/revenue', []],
        'admin.bang-dieu-khien.san-pham-ban-chay' => ['admin/dashboard/top-products', []],
        'admin.bang-dieu-khien.trang-thai-don' => ['admin/dashboard/order-status', []],
        'admin.bao-cao-sang-loc.export' => ['admin/triage-reports/export', []],
        'admin.bao-cao-sang-loc.index' => ['admin/triage-reports', []],
        'admin.bao-cao-sang-loc.outcomeChart' => ['admin/triage-reports/chart/outcome', []],
        'admin.bao-cao-sang-loc.show' => ['admin/triage-reports/{MaSL}', ['MaSL']],
        'admin.bao-cao-sang-loc.trendChart' => ['admin/triage-reports/chart/trend', []],
        'admin.danh-muc.create' => ['admin/categories/create', []],
        'admin.danh-muc.destroy' => ['admin/categories/{category}', ['category']],
        'admin.danh-muc.edit' => ['admin/categories/{category}/edit', ['category']],
        'admin.danh-muc.index' => ['admin/categories', []],
        'admin.danh-muc.store' => ['admin/categories', []],
        'admin.danh-muc.update' => ['admin/categories/{category}', ['category']],
        'admin.don-hang.cap-nhat-trang-thai' => ['admin/orders/{MaDH}/status', ['MaDH']],
        'admin.don-hang.export' => ['admin/orders/export', []],
        'admin.don-hang.huy' => ['admin/orders/{MaDH}/cancel', ['MaDH']],
        'admin.don-hang.index' => ['admin/orders', []],
        'admin.don-hang.show' => ['admin/orders/{MaDH}', ['MaDH']],
        'admin.nguoi-dung.cap-nhat-trang-thai' => ['admin/users/{MaKH}/status', ['MaKH']],
        'admin.nguoi-dung.export' => ['admin/users/export', []],
        'admin.nguoi-dung.index' => ['admin/users', []],
        'admin.nguoi-dung.store' => ['admin/users', []],
        'admin.nguoi-dung.update' => ['admin/users/{MaKH}', ['MaKH']],
        'admin.phien-chat.anh.show' => ['admin/chat-sessions/{sessionId}/images/{image}', ['sessionId', 'image']],
        'admin.phien-chat.index' => ['admin/chat-sessions', []],
        'admin.phien-chat.show' => ['admin/chat-sessions/{sessionId}', ['sessionId']],
        'admin.san-pham.create' => ['admin/products/create', []],
        'admin.san-pham.data' => ['admin/products/data', []],
        'admin.san-pham.destroy' => ['admin/products/{product}', ['product']],
        'admin.san-pham.edit' => ['admin/products/{product}/edit', ['product']],
        'admin.san-pham.export' => ['admin/products/export', []],
        'admin.san-pham.index' => ['admin/products', []],
        'admin.san-pham.show' => ['admin/products/{product}', ['product']],
        'admin.san-pham.store' => ['admin/products', []],
        'admin.san-pham.update' => ['admin/products/{product}', ['product']],
        'admin.ton-kho.dieu-chinh' => ['admin/inventory/adjust', []],
        'admin.ton-kho.export' => ['admin/inventory/export', []],
        'admin.ton-kho.index' => ['admin/inventory', []],
        'admin.ton-kho.sap-het' => ['admin/inventory/low-stock', []],
        'admin.ton-kho.sap-het-han' => ['admin/inventory/expiring', []],
        'chatbot.anh.show' => ['api/chatbot/images/{image}', ['image']],
        'cong-viec-ai.huy' => ['api/ai-jobs/{uuid}', ['uuid']],
        'cong-viec-ai.kiem-tra' => ['api/ai-jobs/{uuid}', ['uuid']],
        'duoc-si.sang-loc.claim' => ['pharmacist/triage-queue/{MaSL}/claim', ['MaSL']],
        'duoc-si.sang-loc.draft' => ['pharmacist/triage-queue/{MaSL}/draft', ['MaSL']],
        'duoc-si.sang-loc.escalate' => ['pharmacist/triage-queue/{MaSL}/escalate', ['MaSL']],
        'duoc-si.sang-loc.index' => ['pharmacist/triage-queue', []],
        'duoc-si.sang-loc.reply' => ['pharmacist/triage-queue/{MaSL}/reply', ['MaSL']],
        'duoc-si.sang-loc.show' => ['pharmacist/triage-queue/{MaSL}', ['MaSL']],
        'gio-hang.cap-nhat' => ['cart/update/{MaSP}', ['MaSP']],
        'gio-hang.index' => ['cart', []],
        'gio-hang.them' => ['cart/add', []],
        'gio-hang.xoa' => ['cart/remove/{MaSP}', ['MaSP']],
        'ho-so.anh-dai-dien.destroy' => ['profile/avatar', []],
        'ho-so.anh-dai-dien.update' => ['profile/avatar', []],
        'ho-so.destroy' => ['profile', []],
        'ho-so.edit' => ['profile', []],
        'ho-so.suc-khoe.destroy' => ['profile/health', []],
        'ho-so.suc-khoe.update' => ['profile/health', []],
        'ho-so.update' => ['profile', []],
        'lien-he' => ['contact', []],
        'lien-he.gui' => ['contact/submit', []],
        'nhac-uong.huy' => ['reminders/{MaNN}/cancel', ['MaNN']],
        'nhac-uong.index' => ['reminders', []],
        'san-pham.danh-muc' => ['products/category/{MaDM}', ['MaDM']],
        'san-pham.goi-y' => ['api/search/suggestions', []],
        'san-pham.index' => ['products', []],
        'san-pham.show' => ['products/{MaSP}', ['MaSP']],
        'san-pham.tim-kiem' => ['search', []],
        'sang-loc.bat-dau-lai' => ['health-check/restart', []],
        'sang-loc.disclaimer.xac-nhan' => ['health-check/disclaimer', []],
        'sang-loc.ket-qua' => ['health-check/result/{MaSL}', ['MaSL']],
        'sang-loc.ket-qua.briefing-pane' => ['health-check/result/{MaSL}/briefing-pane', ['MaSL']],
        'sang-loc.kiem-tra' => ['health-check', []],
        'sang-loc.lam-ro' => ['health-check/clarify', []],
        'sang-loc.lich-su' => ['health-check/history', []],
        'sang-loc.quay-lai' => ['health-check/back', []],
        'sang-loc.quyet-dinh' => ['health-check/decision/{uuid}', ['uuid']],
        'sang-loc.theo-doi.tra-loi' => ['health-check/{MaSL}/follow-up', ['MaSL']],
        'sang-loc.theo-doi.xac-nhan' => ['health-check/{MaSL}/follow-up', ['MaSL']],
        'sang-loc.tra-loi' => ['health-check/answer', []],
        'sang-loc.xin-duoc-si' => ['health-check/result/{MaSL}/pharmacist', ['MaSL']],
        'thanh-toan.index' => ['checkout', []],
        'thanh-toan.thanh-cong' => ['checkout/success/{MaDH}', ['MaDH']],
        'thanh-toan.xu-ly' => ['checkout/process', []],
        'tim-kiem-giong-noi.chuan-hoa' => ['api/voice/normalize', []],
        'trang-chu' => ['/', []],
        'yeu-thich.destroy' => ['favorites/{product}', ['product']],
        'yeu-thich.ids' => ['favorites/ids', []],
        'yeu-thich.section' => ['favorites/section', []],
        'yeu-thich.sync' => ['favorites/sync', []],
        'yeu-thich.toggle' => ['favorites/{product}/toggle', ['product']],
    ];

    private const HOP_DONG_ROUTE_ON_DINH = [
        'login' => ['login', []],
        'logout' => ['logout', []],
        'password.confirm' => ['confirm-password', []],
        'password.email' => ['forgot-password', []],
        'password.request' => ['forgot-password', []],
        'password.reset' => ['reset-password/{token}', ['token']],
        'password.store' => ['reset-password', []],
        'password.update' => ['password', []],
        'register' => ['register', []],
        'storage.local' => ['storage/{path}', ['path']],
        'storage.local.upload' => ['storage/{path}', ['path']],
        'verification.notice' => ['verify-email', []],
        'verification.send' => ['email/verification-notification', []],
        'verification.verify' => ['verify-email/{id}/{hash}', ['id', 'hash']],
    ];

    public function test_khong_con_ten_route_nghiep_vu_cu(): void
    {
        $tenConLai = array_values(array_filter(
            self::TEN_ROUTE_NGHIEP_VU_CU,
            static fn (string $ten): bool => Route::has($ten),
        ));

        $this->assertSame([], $tenConLai, 'Còn tên route nghiệp vụ cũ: '.implode(', ', $tenConLai));
    }

    public function test_du_94_ten_route_nghiep_vu_tieng_viet_va_giu_nguyen_hop_dong(): void
    {
        $this->kiem_tra_hop_dong_route(self::HOP_DONG_ROUTE_NGHIEP_VU);
    }

    public function test_ten_route_auth_va_storage_van_on_dinh(): void
    {
        $this->kiem_tra_hop_dong_route(self::HOP_DONG_ROUTE_ON_DINH);
    }

    private function kiem_tra_hop_dong_route(array $hopDong): void
    {
        foreach ($hopDong as $ten => [$uri, $thamSo]) {
            $route = Route::getRoutes()->getByName($ten);

            $this->assertNotNull($route, "Thiếu route name {$ten}");
            $this->assertSame($uri, $route->uri(), "Route {$ten} đã đổi URI");
            $this->assertSame($thamSo, $route->parameterNames(), "Route {$ten} đã đổi tham số");
        }
    }
}
