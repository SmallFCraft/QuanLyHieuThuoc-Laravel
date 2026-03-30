---
inclusion: always
---

# Service Layer Implementation Guide

## Purpose
Services encapsulate business logic, keeping controllers thin and promoting code reuse and testability.

## When to Create a Service
- Complex business logic spanning multiple models
- Operations requiring transactions
- Logic that needs to be reused across controllers
- Operations with multiple validation steps
- External API integrations

## Service Structure

### Location
`app/Services/`

### Naming Convention
`{Domain}Service.php` (e.g., `CheckoutService`, `OrderService`, `InventoryService`)

### Basic Template
```php
<?php

namespace App\Services;

use App\Models\{Model};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class {Domain}Service
{
    /**
     * Brief description of what this method does.
     *
     * @param type $param Description
     * @return type
     * @throws \Exception
     */
    public function methodName($param): ReturnType
    {
        DB::beginTransaction();
        
        try {
            // Business logic here
            
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error message', ['context' => $data]);
            throw $e;
        }
    }
}
```

## Existing Services

### CheckoutService
**Purpose**: Handle checkout process with transaction safety
**Key Methods**:
- `processCheckout()` - Create order, validate cart, update inventory
- `validateCart()` - Check stock availability and expiration
- `calculateTotal()` - Compute order total

### OrderService
**Purpose**: Manage order lifecycle and status transitions
**Key Methods**:
- `updateStatus()` - Change order status with validation
- `cancelOrder()` - Cancel order and restore inventory
- `validateStatusTransition()` - Ensure valid status changes

### InventoryService
**Purpose**: Handle stock management and tracking
**Key Methods**:
- `adjustStock()` - Increase/decrease stock with locking
- `checkLowStock()` - Identify products below reorder point
- `getExpiringSoon()` - Find products expiring within threshold
- `deductStock()` - Remove stock for order fulfillment
- `restoreStock()` - Return stock on order cancellation

### AuditLogService
**Purpose**: Centralized logging for all system actions
**Key Methods**:
- `log()` - Generic audit log creation
- `logAuthAttempt()` - Track login attempts
- `logLogout()` - Track user logouts
- `logInventoryAdjustment()` - Track stock changes

## Transaction Safety Pattern
Always use transactions for operations that modify multiple records:

```php
public function complexOperation(): Result
{
    return DB::transaction(function () {
        // Lock records that will be modified
        $record = Model::lockForUpdate()->findOrFail($id);
        
        // Perform validations
        if (!$this->validate($record)) {
            throw new \Exception('Validation failed');
        }
        
        // Make changes
        $record->update([...]);
        $related->create([...]);
        
        // Log the operation
        Log::info('Operation completed', ['id' => $record->id]);
        
        return $record;
    });
}
```

## Dependency Injection
Services can depend on other services:

```php
class CheckoutService
{
    protected $inventoryService;
    protected $auditLogService;
    
    public function __construct(
        InventoryService $inventoryService,
        AuditLogService $auditLogService
    ) {
        $this->inventoryService = $inventoryService;
        $this->auditLogService = $auditLogService;
    }
}
```

## Controller Integration
Controllers should delegate to services:

```php
class CheckoutController extends Controller
{
    protected $checkoutService;
    
    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }
    
    public function process(Request $request)
    {
        $validated = $request->validate([...]);
        
        try {
            $order = $this->checkoutService->processCheckout(
                session('cart', []),
                auth()->id(),
                $validated
            );
            
            return redirect()->route('checkout.success', $order->id)
                ->with('success', 'Đơn hàng đã được tạo thành công!');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
```

## Testing Services
Services should be unit tested independently of controllers:

```php
public function test_checkout_process_creates_order()
{
    $service = new CheckoutService();
    $order = $service->processCheckout($cartItems, $userId, $shippingInfo);
    
    $this->assertInstanceOf(Order::class, $order);
    $this->assertEquals('Pending', $order->status);
}
```
