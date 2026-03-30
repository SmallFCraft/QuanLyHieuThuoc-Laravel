<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index()
    {
        $categories = Category::orderBy('name')->get();
        return view('admin.products.index', compact('categories'));
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $categories = Category::orderBy('name')->get();
        
        return view('admin.products.create', compact('categories'));
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(ProductRequest $request)
    {
        $data = $request->validated();
        
        // Handle image upload
        if ($request->hasFile('image')) {
            $data['image'] = $this->handleImageUpload($request->file('image'));
        }
        
        $product = Product::create($data);
        
        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Tạo sản phẩm thành công');
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product)
    {
        $product->load('category');
        
        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(Product $product)
    {
        $categories = Category::orderBy('name')->get();
        
        return view('admin.products.edit', compact('product', 'categories'));
    }

    /**
     * Update the specified product in storage.
     */
    public function update(ProductRequest $request, Product $product)
    {
        $data = $request->validated();
        
        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($product->image) {
                Storage::disk('public')->delete('products/' . $product->image);
            }
            
            $data['image'] = $this->handleImageUpload($request->file('image'));
        }
        
        $product->update($data);
        
        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Cập nhật sản phẩm thành công');
    }

    /**
     * Remove the specified product from storage (soft delete).
     */
    public function destroy(Product $product)
    {
        $product->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Xóa sản phẩm thành công'
        ]);
    }

    /**
     * Get products data for DataTables.
     */
    public function data(Request $request)
    {
        // Total records - count BEFORE building query
        $totalRecords = Product::count();
        
        $query = Product::query();
        
        // Filter by Category
        if ($request->has('category_id') && $request->category_id != '') {
            $query->where('category_id', $request->category_id);
        }

        // Search
        if ($request->has('search') && $request->search['value']) {
            $search = $request->search['value'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('batch_number', 'like', "%{$search}%")
                  ->orWhereHas('category', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }
        
        // Filtered records count
        $filteredRecords = $query->count();
        
        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->columns[$request->order[0]['column']]['name'];
            $orderDir = $request->order[0]['dir'];
            
            if ($orderColumn === 'category.name') {
                $query->join('categories', 'products.category_id', '=', 'categories.id')
                      ->orderBy('categories.name', $orderDir)
                      ->select('products.*');
            } else {
                $query->orderBy($orderColumn, $orderDir);
            }
        }
        
        // Pagination - ONLY load records needed for current page
        $start = $request->start ?? 0;
        $length = $request->length ?? 10;
        $products = $query->with('category')->skip($start)->take($length)->get();
        
        // Format data - return raw data instead of HTML
        $data = $products->map(function($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category->name,
                'price' => $product->price,
                'formatted_price' => $product->formatted_price,
                'stock_quantity' => $product->stock_quantity,
                'is_low_stock' => $product->isLowStock(),
                'expiration_date' => $product->expiration_date->format('Y-m-d'),
                'formatted_expiration_date' => $product->expiration_date->format('d/m/Y'),
                'is_expired' => $product->isExpired(),
                'is_expiring_soon' => $product->isExpiringSoon(),
                'batch_number' => $product->batch_number,
                'image_url' => $product->image ? asset('storage/' . $product->image) : null,
            ];
        });
        
        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data
        ]);
    }

    /**
     * Handle image upload.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return string
     */
    private function handleImageUpload($file): string
    {
        // Generate unique filename
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        
        // Store in public/products directory
        $file->storeAs('products', $filename, 'public');
        
        return $filename;
    }
}
