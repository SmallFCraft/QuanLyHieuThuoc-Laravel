<?php

namespace App\Http\Controllers;

use App\Services\CheckoutService;
use App\Models\Product;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
                        'subtotal' => $product->price_vnd * $quantity
                    ];
                    $total += $product->price_vnd * $quantity;
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
                    return redirect()->route('cart.index')->with('error', 'Giỏ hàng trống.');
                }

                $completedKey = $this->completedCheckoutKey($userId, $cart);
                $completedOrderId = Cache::get($completedKey);

                if ($completedOrderId) {
                    session()->forget('cart');

                    return redirect()->route('checkout.success', $completedOrderId)
                        ->with('success', 'Đơn hàng đã được xử lý.');
                }

                $cartItems = $this->normalizeCartItems($cart);

                $order = $this->checkoutService->processCheckout(
                    $cartItems,
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

                Cache::put($completedKey, $order->id, now()->addMinutes(10));
                session()->forget('cart');

                return redirect()->route('checkout.success', $order->id)
                    ->with('success', 'Đặt hàng thành công!');
            });
        } catch (LockTimeoutException) {
            return back()->with('error', 'Đơn hàng đang được xử lý. Vui lòng chờ trong giây lát.');

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

    private function normalizeCartItems(array $cart): array
    {
        $cartItems = [];

        foreach ($cart as $productId => $quantity) {
            $cartItems[] = [
                'product_id' => (int) $productId,
                'quantity' => (int) $quantity
            ];
        }

        return $cartItems;
    }
}
