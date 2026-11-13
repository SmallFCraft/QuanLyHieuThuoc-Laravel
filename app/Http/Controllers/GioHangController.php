<?php

namespace App\Http\Controllers;

use App\Models\SanPham;
use App\Services\AnToanThuocService;
use App\Support\CartLine;
use Illuminate\Http\Request;

class GioHangController extends Controller
{
    /**
     * Display the shopping cart.
     */
    public function index()
    {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return view('cart.index', ['cartItems' => [], 'total' => 0]);
        }
        
        // OPTIMIZED: Load all products in 1 query instead of N+1
        $productIds = array_keys($cart);
        $sanPhams = SanPham::whereIn('ma_sp', $productIds)
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->get()
            ->keyBy('ma_sp'); // Index by MaSP for O(1) lookup
        
        $cacMucGioHang = [];
        $tongTien = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($sanPhams[$productId])) {
                $sanPham = $sanPhams[$productId];
                $cacMucGioHang[] = [
                    'sanPham' => $sanPham,
                    'quantity' => $quantity,
                    'subtotal' => $sanPham->price_vnd * $quantity
                ];
                $tongTien += $sanPham->price_vnd * $quantity;
            }
        }

        return view('cart.index', compact('cacMucGioHang', 'tongTien'));
    }

    /**
     * Add a product to the cart.
     */
    public function add(Request $request, AnToanThuocService $anToanThuoc)
    {
        $request->validate([
            'MaSP' => 'required|exists:san_phams,ma_sp',
            'quantity' => 'required|integer|min:1|max:999',
            'confirm_warning' => 'nullable|boolean',
        ]);

        $cart = session()->get('cart', []);
        $wantsJson = $request->expectsJson();

        // SECURITY: Limit number of unique items in cart
        if (count($cart) >= 100 && ! isset($cart[$request->MaSP])) {
            $tinNhan = 'Giỏ hàng đã đạt giới hạn 100 sản phẩm khác nhau.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $tinNhan], 422);
            }

            return back()->with('error', $tinNhan);
        }

        // SECURITY: Limit total quantity in cart
        $tongTienQuantity = array_sum($cart);
        if ($tongTienQuantity + $request->quantity > 1000) {
            $tinNhan = 'Tổng số lượng trong giỏ hàng không được vượt quá 1000.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $tinNhan], 422);
            }

            return back()->with('error', $tinNhan);
        }

        $sanPham = SanPham::findOrFail($request->MaSP);

        // Validate product is not expired
        if ($sanPham->isExpired()) {
            $tinNhan = 'Sản phẩm đã hết hạn. Không thể thêm vào giỏ hàng.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $tinNhan], 422);
            }

            return back()->with('error', $tinNhan);
        }

        // Validate stock availability
        if ($sanPham->so_luong_ton < $request->quantity) {
            $tinNhan = __('messages.cart.stock_insufficient');
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $tinNhan], 422);
            }

            return back()->with('error', $tinNhan);
        }

        // Drug safety runs before the cart is touched: a blocked item must never land
        // in the session, even for one request.
        $userId = (int) auth()->id();
        $confirmed = $request->boolean('confirm_warning');
        $safety = $anToanThuoc->kiemTraThemVaoGio($sanPham, CartLine::rowsFrom($cart), $userId);
        $warnings = $safety['warnings'];

        if ($safety['blocking'] && ! $confirmed) {
            $tinNhan = __('messages.drug_safety.blocked_message');

            if ($wantsJson) {
                return response()->json([
                    'success' => false,
                    'message' => $tinNhan,
                    'requires_confirmation' => true,
                    'drug_warnings' => $warnings,
                ], 409);
            }

            // Redirect to the cart, not back(): the confirmation block lives there, and a
            // product page cannot render it.
            return redirect()->route('gio-hang.index')
                ->with('error', $tinNhan)
                ->with('drug_warnings', $warnings)
                ->with('drug_warning_product', $sanPham->ma_sp)
                ->with('drug_warning_quantity', (int) $request->quantity);
        }

        // If product already in cart, update quantity
        if (isset($cart[$request->MaSP])) {
            $newQuantity = $cart[$request->MaSP] + $request->quantity;

            // Check if new quantity exceeds stock
            if ($newQuantity > $sanPham->so_luong_ton) {
                $tinNhan = __('messages.cart.stock_exceeded');
                if ($wantsJson) {
                    return response()->json(['success' => false, 'message' => $tinNhan], 422);
                }

                return back()->with('error', $tinNhan);
            }

            $cart[$request->MaSP] = $newQuantity;
        } else {
            $cart[$request->MaSP] = $request->quantity;
        }

        session()->put('cart', $cart);

        if ($safety['blocking'] && $confirmed) {
            $anToanThuoc->ghiNhatKyBoQua($userId, $sanPham, $warnings);
        }

        $successMessage = 'Đã thêm sản phẩm vào giỏ hàng.';

        if ($wantsJson) {
            return response()->json([
                'success' => true,
                'message' => $successMessage,
                'cart_count' => count($cart),
                'requires_confirmation' => false,
                'drug_warnings' => $warnings,
            ]);
        }

        $redirect = back()->with('success', $successMessage);

        if ($warnings !== []) {
            $redirect->with('drug_warnings', $warnings);
        }

        return $redirect;
    }

    /**
     * Update cart item quantity.
     */
    public function update(Request $request, $productId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        $sanPham = SanPham::findOrFail($productId);

        // Validate stock availability
        if ($sanPham->so_luong_ton < $request->quantity) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.cart.stock_exceeded')
                ], 400);
            }
            return back()->with('error', __('messages.cart.stock_exceeded'));
        }

        $cart = session()->get('cart', []);
        
        if (isset($cart[$productId])) {
            $cart[$productId] = $request->quantity;
            session()->put('cart', $cart);
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Đã cập nhật số lượng.',
                    'cart_count' => count($cart)
                ]);
            }
            return back()->with('success', 'Đã cập nhật số lượng.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => __('messages.cart.not_in_cart')
            ], 404);
        }
        return back()->with('error', __('messages.cart.not_in_cart'));
    }

    /**
     * Remove a product from the cart.
     */
    public function remove($productId)
    {
        $cart = session()->get('cart', []);
        
        if (isset($cart[$productId])) {
            unset($cart[$productId]);
            session()->put('cart', $cart);
            
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Đã xóa sản phẩm khỏi giỏ hàng.',
                    'cart_count' => count($cart)
                ]);
            }
            return back()->with('success', 'Đã xóa sản phẩm khỏi giỏ hàng.');
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => __('messages.cart.not_in_cart')
            ], 404);
        }
        return back()->with('error', __('messages.cart.not_in_cart'));
    }
}
