<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
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
        $products = Product::whereIn('id', $productIds)
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->get()
            ->keyBy('id'); // Index by ID for O(1) lookup
        
        $cartItems = [];
        $total = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($products[$productId])) {
                $product = $products[$productId];
                $cartItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'subtotal' => $product->price * $quantity
                ];
                $total += $product->price * $quantity;
            }
        }

        return view('cart.index', compact('cartItems', 'total'));
    }

    /**
     * Add a product to the cart.
     */
    public function add(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:999'
        ]);

        $cart = session()->get('cart', []);
        $wantsJson = $request->expectsJson();

        // SECURITY: Limit number of unique items in cart
        if (count($cart) >= 100 && ! isset($cart[$request->product_id])) {
            $message = 'Giỏ hàng đã đạt giới hạn 100 sản phẩm khác nhau.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        // SECURITY: Limit total quantity in cart
        $totalQuantity = array_sum($cart);
        if ($totalQuantity + $request->quantity > 1000) {
            $message = 'Tổng số lượng trong giỏ hàng không được vượt quá 1000.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        $product = Product::findOrFail($request->product_id);

        // Validate product is not expired
        if ($product->isExpired()) {
            $message = 'Sản phẩm đã hết hạn. Không thể thêm vào giỏ hàng.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        // Validate stock availability
        if ($product->stock_quantity < $request->quantity) {
            $message = 'Sản phẩm không đủ số lượng trong kho.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        // If product already in cart, update quantity
        if (isset($cart[$request->product_id])) {
            $newQuantity = $cart[$request->product_id] + $request->quantity;

            // Check if new quantity exceeds stock
            if ($newQuantity > $product->stock_quantity) {
                $message = 'Số lượng vượt quá tồn kho.';
                if ($wantsJson) {
                    return response()->json(['success' => false, 'message' => $message], 422);
                }

                return back()->with('error', $message);
            }

            $cart[$request->product_id] = $newQuantity;
        } else {
            $cart[$request->product_id] = $request->quantity;
        }

        session()->put('cart', $cart);

        $successMessage = 'Đã thêm sản phẩm vào giỏ hàng.';

        if ($wantsJson) {
            return response()->json([
                'success' => true,
                'message' => $successMessage,
                'cart_count' => count($cart),
            ]);
        }

        return back()->with('success', $successMessage);
    }

    /**
     * Update cart item quantity.
     */
    public function update(Request $request, $productId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        $product = Product::findOrFail($productId);

        // Validate stock availability
        if ($product->stock_quantity < $request->quantity) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Số lượng vượt quá tồn kho.'
                ], 400);
            }
            return back()->with('error', 'Số lượng vượt quá tồn kho.');
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
                'message' => 'Sản phẩm không có trong giỏ hàng.'
            ], 404);
        }
        return back()->with('error', 'Sản phẩm không có trong giỏ hàng.');
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
                'message' => 'Sản phẩm không có trong giỏ hàng.'
            ], 404);
        }
        return back()->with('error', 'Sản phẩm không có trong giỏ hàng.');
    }
}
