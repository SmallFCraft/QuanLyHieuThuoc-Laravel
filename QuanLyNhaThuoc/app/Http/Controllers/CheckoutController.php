<?php

namespace App\Http\Controllers;

use App\Services\CheckoutService;
use App\Models\Product;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    protected $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * Display the checkout page.
     */
    public function index()
    {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('cart.index')->with('error', 'Giỏ hàng trống.');
        }

        // OPTIMIZED: Load all products in 1 query instead of N+1
        $productIds = array_keys($cart);
        $products = Product::whereIn('id', $productIds)
            ->notExpired()
            ->get()
            ->keyBy('id');
        
        $cartItems = [];
        $total = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($products[$productId])) {
                $product = $products[$productId];
                if ($product->stock_quantity >= $quantity) {
                    $cartItems[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                        'subtotal' => $product->price * $quantity
                    ];
                    $total += $product->price * $quantity;
                }
            }
        }

        return view('checkout.index', compact('cartItems', 'total'));
    }

    /**
     * Process the checkout.
     */
    public function process(Request $request)
    {
        $request->validate([
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

        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('cart.index')->with('error', 'Giỏ hàng trống.');
        }

        // Prepare cart items for checkout service
        $cartItems = [];
        foreach ($cart as $productId => $quantity) {
            $cartItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity
            ];
        }

        try {
            $order = $this->checkoutService->processCheckout(
                $cartItems,
                auth()->id(),
                [
                    'address' => $request->shipping_address,
                    'phone' => $request->shipping_phone,
                    'customer_name' => $request->customer_name,
                    'customer_email' => $request->customer_email,
                    'delivery_time' => $request->delivery_time,
                    'notes' => $request->order_notes
                ]
            );

            // Clear cart after successful checkout
            session()->forget('cart');

            return redirect()->route('checkout.success', $order->id)
                ->with('success', 'Đặt hàng thành công!');
                
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Display order success page.
     */
    public function success($orderId)
    {
        $order = auth()->user()->orders()->with('items.product')->findOrFail($orderId);
        
        return view('checkout.success', compact('order'));
    }
}
