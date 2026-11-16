<?php

namespace App\Http\Controllers;

use App\Services\ThanhToanService;
use App\Services\AnToanThuocService;
use App\Models\SanPham;
use App\Support\CartLine;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ThanhToanController extends Controller
{
    protected $thanhToanService;

    public function __construct(ThanhToanService $thanhToanService)
    {
        $this->thanhToanService = $thanhToanService;
    }

    /**
     * Hiển thị trang checkout.
     */
    public function index(AnToanThuocService $anToanThuoc)
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return redirect()->route('gio-hang.index')->with('error', 'Giỏ hàng trống.');
        }

        // TỐI ƯU: Tải mỗi sản phẩm trong 1 truy vấn thay vì N+1
        $productIds = array_keys($cart);
        $sanPhams = SanPham::whereIn('ma_sp', $productIds)
            ->notExpired()
            ->get()
            ->keyBy('ma_sp');
        
        $cacMucGioHang = [];
        $tongTien = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($sanPhams[$productId])) {
                $sanPham = $sanPhams[$productId];
                if ($sanPham->so_luong_ton >= $quantity) {
                    $cacMucGioHang[] = [
                        'sanPham' => $sanPham,
                        'quantity' => $quantity,
                        'subtotal' => $sanPham->price_vnd * $quantity
                    ];
                    $tongTien += $sanPham->price_vnd * $quantity;
                }
            }
        }

        // Chỉ mang tính thông tin: khách đã xác nhận mỗi cảnh báo chặn khi sản phẩm
        // được thêm vào, nên checkout không bao giờ bị chặn tại đây.
        $canhBaoThuoc = $anToanThuoc->kiemTraGioHang(
            CartLine::rowsFrom($cart),
            (int) auth()->id()
        )['warnings'];

        return view('checkout.index', compact('cacMucGioHang', 'tongTien', 'canhBaoThuoc'));
    }

    /**
     * Xử lý checkout.
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'shipping_address' => 'required|string|max:500',
            'shipping_phone' => ['required', 'regex:/^(\+84|0)[0-9]{9}$/'],
            'customer_email' => 'nullable|email|max:255',
            'delivery_time' => 'nullable|string|in:anytime,morning,afternoon,evening',
            'order_notes' => 'nullable|string|max:1000'
        ], [
            'customer_name.required' => 'Họ và tên là bắt buộc.',
            'shipping_address.required' => 'Địa chỉ giao hàng là bắt buộc.',
            'shipping_phone.required' => 'Số điện thoại là bắt buộc.',
            'shipping_phone.regex' => 'Số điện thoại không hợp lệ.',
            'customer_email.email' => 'Email không hợp lệ.'
        ]);

        $userId = (int) auth()->id();
        $lock = Cache::lock($this->checkoutLockKey($userId), 10);

        try {
            return $lock->block(3, function () use ($validated, $userId) {
                $cart = session()->get('cart', []);

                if (empty($cart)) {
                    return redirect()->route('gio-hang.index')->with('error', 'Giỏ hàng trống.');
                }

                $completedKey = $this->completedCheckoutKey($userId, $cart);
                $completedOrderId = Cache::get($completedKey);

                if ($completedOrderId) {
                    session()->forget('cart');

                    return redirect()->route('thanh-toan.thanh-cong', $completedOrderId)
                        ->with('success', 'Đơn hàng đã được xử lý.');
                }

                $cacMucGioHang = CartLine::rowsFrom($cart);

                $donHang = $this->thanhToanService->xuLyThanhToan(
                    $cacMucGioHang,
                    $userId,
                    [
                        'address' => $validated['shipping_address'],
                        'phone' => $validated['shipping_phone'],
                        'customer_name' => $validated['customer_name'],
                        'customer_email' => $validated['customer_email'] ?? null,
                        'delivery_time' => $validated['delivery_time'] ?? null,
                        'notes' => $validated['order_notes'] ?? null
                    ]
                );

                Cache::put($completedKey, $donHang->ma_dh, now()->addMinutes(10));
                session()->forget('cart');

                return redirect()->route('thanh-toan.thanh-cong', $donHang->ma_dh)
                    ->with('success', 'Đặt hàng thành công!');
            });
        } catch (LockTimeoutException) {
            return back()->with('error', 'Đơn hàng đang được xử lý. Vui lòng chờ trong giây lát.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Hiển thị trang thành công của đơn hàng.
     */
    public function success($MaDH)
    {
        $donHang = auth()->user()->donHangs()->with('chiTietDonHangs.sanPham')->findOrFail($MaDH);
        
        return view('checkout.success', compact('donHang'));
    }

    private function checkoutLockKey(int $userId): string
    {
        return "checkout:user:{$userId}";
    }

    private function completedCheckoutKey(int $userId, array $cart): string
    {
        return "checkout:completed:{$userId}:" . $this->cartHash($cart);
    }

    private function cartHash(array $cart): string
    {
        ksort($cart);

        return hash('sha256', json_encode($cart));
    }
}
