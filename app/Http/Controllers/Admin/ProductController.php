<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AdminProductsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\LoSanPham;
use App\Support\ProductCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index()
    {
        $categories = DanhMuc::orderBy('ten')->get();
        return view('admin.products.index', compact('categories'));
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $categories = DanhMuc::orderBy('ten')->get();
        
        return view('admin.products.create', compact('categories'));
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(ProductRequest $request)
    {
        $data = $this->productPayload($request->validated());
        $danhMuc = DanhMuc::findOrFail($data['ma_dm']);
        $data['ma_san_pham'] = ProductCodeGenerator::productCodeForCategory($danhMuc);
        $uploadedImage = null;

        // Handle image upload
        if ($request->hasFile('image')) {
            $uploadedImage = $this->handleImageUpload($request->file('image'));
            $data['hinh_anh'] = $uploadedImage;
        }

        try {
            DB::transaction(fn () => SanPham::create($data));
        } catch (\Throwable $e) {
            if ($uploadedImage) {
                Storage::disk('public')->delete($uploadedImage);
            }

            report($e);

            return back()
                ->withInput()
                ->with('error', 'Không thể tạo sản phẩm. Vui lòng thử lại.');
        }
        
        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Tạo sản phẩm thành công');
    }

    /**
     * Display the specified product.
     */
    public function show(SanPham $product)
    {
        $product->load('danhMuc');
        
        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(SanPham $product)
    {
        $categories = DanhMuc::orderBy('ten')->get();
        
        return view('admin.products.edit', compact('product', 'categories'));
    }

    /**
     * Update the specified product in storage.
     */
    public function update(ProductRequest $request, SanPham $product)
    {
        $data = $this->productPayload($request->validated());
        $oldImage = $product->hinh_anh;
        $newImage = null;

        // Handle image upload
        if ($request->hasFile('image')) {
            $newImage = $this->handleImageUpload($request->file('image'));
            $data['hinh_anh'] = $newImage;
        }

        try {
            DB::transaction(function () use ($product, $data) {
                $product->update($data);
                $this->syncPrimaryBatch($product, $data);
            });
        } catch (\Throwable $e) {
            if ($newImage) {
                Storage::disk('public')->delete($newImage);
            }

            report($e);

            return back()
                ->withInput()
                ->with('error', 'Không thể cập nhật sản phẩm. Vui lòng thử lại.');
        }

        if ($newImage && $oldImage && $oldImage !== $newImage) {
            Storage::disk('public')->delete($oldImage);
        }
        
        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Cập nhật sản phẩm thành công');
    }

    /**
     * Remove the specified product from storage (soft delete).
     */
    public function destroy(SanPham $product)
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
        $totalRecords = SanPham::count();
        
        $query = SanPham::query();
        
        // Filter by DanhMuc
        if ($request->has('ma_dm') && $request->ma_dm != '') {
            $query->where('ma_dm', $request->ma_dm);
        }

        // Search
        if ($request->has('search') && $request->search['value']) {
            $query->search($request->search['value']);
        }
        
        // Filtered records count
        $filteredRecords = $query->count();
        
        // DataTables sort keys sent by the client (kept) mapped to the renamed columns.
        $allowedOrderColumns = [
            'ma_sp' => 'ma_sp',
            'product_code' => 'ma_san_pham',
            'name' => 'ten',
            'price' => 'gia',
            'stock_quantity' => 'so_luong_ton',
            'expiration_date' => 'ngay_het_han',
            'batch_number' => 'so_lo',
            'category.name' => 'category.name',
        ];

        // Sorting
        if ($request->has('order')) {
            $columnIndex = (int) $request->input('order.0.column', 0);
            $requestedColumn = (string) $request->input("columns.$columnIndex.name", 'ma_sp');
            $orderColumn = $allowedOrderColumns[$requestedColumn] ?? 'ma_sp';
            $orderDir = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

            if ($orderColumn === 'category.name') {
                $query->join('danh_mucs', 'san_phams.ma_dm', '=', 'danh_mucs.ma_dm')
                      ->orderBy('danh_mucs.ten', $orderDir)
                      ->select('san_phams.*');
            } else {
                $query->orderBy($orderColumn, $orderDir);
            }
        }
        
        // Pagination - ONLY load records needed for current page
        $start = max(0, $request->integer('start', 0));
        $length = min(max($request->integer('length', 10), 1), 100);
        $sanPhams = $query->with('danhMuc')->skip($start)->take($length)->get();
        
        // Format data - return raw data instead of HTML
        $data = $sanPhams->map(function($product) {
            return [
                'ma_sp' => $product->ma_sp,
                'product_code' => $product->ma_san_pham,
                'display_code' => $product->display_code,
                'name' => $product->ten,
                'category' => $product->danhMuc->ten,
                'price' => $product->price_vnd,
                'formatted_price' => $product->formatted_price,
                'stock_quantity' => $product->so_luong_ton,
                'is_low_stock' => $product->isLowStock(),
                'expiration_date' => $product->ngay_het_han->format('Y-m-d'),
                'formatted_expiration_date' => $product->ngay_het_han->format('d/m/Y'),
                'is_expired' => $product->isExpired(),
                'is_expiring_soon' => $product->isExpiringSoon(),
                'batch_number' => $product->so_lo,
                'image_url' => $product->image_url,
            ];
        });
        
        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data
        ]);
    }

    public function export(Request $request)
    {
        return Excel::download(
            new AdminProductsExport(
                categoryId: $request->filled('ma_dm') ? $request->string('ma_dm')->toString() : null,
                search: $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            ),
            'san-pham.xlsx'
        );
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
        
        // Return path relative to storage disk (includes products/ prefix)
        return 'products/' . $filename;
    }

    /**
     * Map the validated form keys to the Vietnamese column names of san_phams.
     * The form contract (name/price/...) stays unchanged; only the storage keys move.
     */
    private function productPayload(array $validated): array
    {
        return [
            'ma_dm' => $validated['ma_dm'],
            'ten' => $validated['name'],
            'thanh_phan' => $validated['ingredients'] ?? null,
            'cach_dung' => $validated['usage'] ?? null,
            'gia' => $validated['price'],
            'so_lo' => $validated['batch_number'],
            'ngay_het_han' => $validated['expiration_date'],
            'so_luong_ton' => (int) $validated['stock_quantity'],
            'muc_canh_bao' => (int) $validated['reorder_point'],
        ];
    }

    /**
     * Keep the editable primary product batch aligned with product fields.
     */
    private function syncPrimaryBatch(SanPham $product, array $data): void
    {
        $payloadLo = [
            'so_lo' => $data['so_lo'],
            'ngay_het_han' => $data['ngay_het_han'],
            'so_luong_ton' => (int) $data['so_luong_ton'],
            'muc_canh_bao' => (int) $data['muc_canh_bao'],
        ];

        $lo = $product->cacLo()
            ->lockForUpdate()
            ->orderBy('ngay_het_han')
            ->orderBy('ma_lo')
            ->first();

        if ($lo instanceof LoSanPham) {
            $lo->update($payloadLo);
            return;
        }

        $product->cacLo()->create($payloadLo);
    }
}
