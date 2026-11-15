<?php

namespace App\Http\Controllers\Admin;

use App\Exports\QuanTriSanPhamExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\SanPhamRequest;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\LoSanPham;
use App\Support\TaoMaSanPham;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class SanPhamController extends Controller
{
    /**
     * Hiển thị danh sách sản phẩm.
     */
    public function index()
    {
        $danhMucs = DanhMuc::orderBy('ten')->get();
        return view('admin.products.index', compact('danhMucs'));
    }

    /**
     * Hiển thị form tạo sản phẩm mới.
     */
    public function create()
    {
        $danhMucs = DanhMuc::orderBy('ten')->get();

        return view('admin.products.create', compact('danhMucs'));
    }

    /**
     * Lưu sản phẩm mới tạo vào storage.
     */
    public function store(SanPhamRequest $request)
    {
        $data = $this->productPayload($request->validated());
        $danhMuc = DanhMuc::findOrFail($data['ma_dm']);
        $data['ma_san_pham'] = TaoMaSanPham::maSanPhamTheoDanhMuc($danhMuc);
        $uploadedImage = null;

        // Xử lý tải lên ảnh
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
            ->route('admin.san-pham.index')
            ->with('success', 'Tạo sản phẩm thành công');
    }

    /**
     * Hiển thị sản phẩm chỉ định.
     */
    public function show(SanPham $product)
    {
        $product->load('danhMuc');

        return view('admin.products.show', ['sanPham' => $product]);
    }

    /**
     * Hiển thị form sửa sản phẩm chỉ định.
     */
    public function edit(SanPham $product)
    {
        $danhMucs = DanhMuc::orderBy('ten')->get();

        return view('admin.products.edit', ['sanPham' => $product, 'danhMucs' => $danhMucs]);
    }

    /**
     * Cập nhật sản phẩm chỉ định trong storage.
     */
    public function update(SanPhamRequest $request, SanPham $product)
    {
        $data = $this->productPayload($request->validated());
        $oldImage = $product->hinh_anh;
        $newImage = null;

        // Xử lý tải lên ảnh
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
            ->route('admin.san-pham.index')
            ->with('success', 'Cập nhật sản phẩm thành công');
    }

    /**
     * Xoá sản phẩm chỉ định khỏi storage (soft delete).
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
     * Lấy dữ liệu sản phẩm cho DataTables.
     */
    public function data(Request $request)
    {
        // Tổng số bản ghi - đếm TRƯỚC khi dựng query
        $totalRecords = SanPham::count();

        $query = SanPham::query();

        // Lọc theo DanhMuc
        if ($request->has('MaDM') && $request->MaDM != '') {
            $query->where('ma_dm', $request->MaDM);
        }

        // Tìm kiếm
        if ($request->has('search') && $request->search['value']) {
            $query->search($request->search['value']);
        }

        // Số bản ghi sau khi lọc
        $filteredRecords = $query->count();

        // Các sort key DataTables client gửi về (giữ nguyên) được ánh xạ sang các cột đã đổi tên.
        $allowedOrderColumns = [
            'MaSP' => 'ma_sp',
            'product_code' => 'ma_san_pham',
            'name' => 'ten',
            'price' => 'gia',
            'stock_quantity' => 'so_luong_ton',
            'expiration_date' => 'ngay_het_han',
            'batch_number' => 'so_lo',
            'category.name' => 'category.name',
        ];

        // Sắp xếp
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
        
        // Phân trang - CHỈ tải những bản ghi cần cho trang hiện tại
        $start = max(0, $request->integer('start', 0));
        $length = min(max($request->integer('length', 10), 1), 100);
        $sanPhams = $query->with('danhMuc')->skip($start)->take($length)->get();

        // Định dạng dữ liệu - trả về dữ liệu thô thay vì HTML
        $data = $sanPhams->map(function($product) {
            return [
                'MaSP' => $product->ma_sp,
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
            new QuanTriSanPhamExport(
                categoryId: $request->filled('MaDM') ? $request->string('MaDM')->toString() : null,
                search: $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            ),
            'san-pham.xlsx'
        );
    }

    /**
     * Xử lý tải lên ảnh.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return string
     */
    private function handleImageUpload($file): string
    {
        // Tạo tên file duy nhất
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        // Lưu vào thư mục public/products
        $file->storeAs('products', $filename, 'public');

        // Trả về đường dẫn tương đối với storage disk (kèm prefix products/)
        return 'products/' . $filename;
    }

    /**
     * Ánh xạ các key form đã validate sang tên cột tiếng Việt của san_phams.
     * Contract của form (name/price/...) giữ nguyên; chỉ các key storage thay đổi.
     */
    private function productPayload(array $validated): array
    {
        return [
            'ma_dm' => $validated['MaDM'],
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
     * Giữ lô sản phẩm chính có thể chỉnh sửa đồng bộ với các field sản phẩm.
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
