---
inclusion: always
description: Inventory management system with FEFO strategy, stock tracking, and product lifecycle management
---

# Inventory Management System

## FEFO Strategy
**First Expired, First Out** - Products with earliest expiration dates are sold first.

## Key Concepts

### Stock Tracking
- `stock_quantity` - Current available quantity
- `reorder_point` - Threshold for low stock alerts
- `batch_number` - Unique identifier for product batches
- `expiration_date` - When product expires

### Product States
- **Active**: In stock, not expired
- **Low Stock**: Below reorder point
- **Expiring Soon**: Within 180 days of expiration
- **Expired**: Past expiration date
- **Out of Stock**: stock_quantity = 0

## InventoryService Methods

### adjustStock()
Increase or decrease stock with transaction safety:
```php
$inventoryService->adjustStock(
    productId: $productId,
    quantity: 10,        // Positive = increase, negative = decrease
    reason: 'restock',   // Reason code for audit
    userId: auth()->id()
);
```

**Features**:
- Uses `lockForUpdate()` to prevent race conditions
- Validates non-negative stock
- Logs adjustment in audit trail
- Throws exception on invalid operations

### deductStock()
Remove stock for order fulfillment:
```php
$inventoryService->deductStock(
    productId: $productId,
    quantity: 5,
    orderId: $order->id
);
```

**FEFO Implementation**:
- Automatically selects products with earliest expiration
- Skips expired products
- Ensures sufficient stock available

### restoreStock()
Return stock on order cancellation:
```php
$inventoryService->restoreStock(
    productId: $productId,
    quantity: 5,
    orderId: $order->id
);
```

### checkLowStock()
Find products below reorder point:
```php
$lowStockProducts = $inventoryService->checkLowStock();
```

Returns products where `stock_quantity <= reorder_point`.

### getExpiringSoon()
Find products expiring within threshold:
```php
$expiringProducts = $inventoryService->getExpiringSoon(days: 180);
```

## Product Model Scopes

### notExpired()
Filter out expired products:
```php
$products = Product::notExpired()->get();
```

### expiringSoon()
Products expiring within days:
```php
$products = Product::expiringSoon(90)->get();
```

### lowStock()
Products below reorder point:
```php
$products = Product::lowStock()->get();
```

### fefo()
Order by expiration date (earliest first):
```php
$products = Product::fefo()->get();
```

## Checkout Integration

### Stock Validation
Before checkout:
1. Check product not expired
2. Verify sufficient stock
3. Lock product row for update
4. Deduct stock atomically

```php
// In CheckoutService
$product = Product::lockForUpdate()->findOrFail($productId);

if ($product->isExpired()) {
    throw new \Exception('Sản phẩm đã hết hạn');
}

if ($product->stock_quantity < $quantity) {
    throw new \Exception('Không đủ hàng trong kho');
}

$product->stock_quantity -= $quantity;
$product->save();
```

## Order Cancellation

### Inventory Restoration
When order cancelled:
1. Validate order status (must be Pending)
2. Restore stock for each order item
3. Update order status
4. Log cancellation

```php
// In OrderService
foreach ($order->items as $item) {
    $product = Product::lockForUpdate()->find($item->product_id);
    if ($product) {
        $product->stock_quantity += $item->quantity;
        $product->save();
    }
}
```

## Admin Dashboard Alerts

### Low Stock Alert
```php
$lowStockCount = Product::lowStock()->count();
```

### Expiring Soon Alert
```php
$expiringSoonCount = Product::expiringSoon(180)->count();
```

### Expired Products
```php
$expiredCount = Product::where('expiration_date', '<', now())->count();
```

## Best Practices

1. **Always use transactions** for multi-step inventory operations
2. **Lock rows** with `lockForUpdate()` when modifying stock
3. **Validate expiration** before allowing product in cart
4. **Log all adjustments** with reason codes
5. **Set appropriate reorder points** based on sales velocity
6. **Monitor expiring products** and plan promotions
7. **Use FEFO scope** when retrieving products for sale
8. **Soft delete products** to maintain order history
