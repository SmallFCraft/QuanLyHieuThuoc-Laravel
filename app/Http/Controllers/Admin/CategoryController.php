<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DanhMuc;
use App\Support\ProductCodeGenerator;
use App\Support\VietnameseText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories.
     */
    public function index()
    {
        $categories = DanhMuc::withCount('sanPhams')->orderBy('ten')->get();
        
        return view('admin.categories.index', compact('categories'));
    }

    /**
     * Store a newly created category in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'unique:danh_mucs,ten', $this->uniqueFoldedName()],
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('danh_mucs', 'ma_danh_muc')],
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ], [
            'name.required' => 'Tên danh mục là bắt buộc',
            'name.unique' => 'Tên danh mục đã tồn tại',
            'name.max' => 'Tên danh mục không được vượt quá 255 ký tự',
            'code.unique' => 'Mã viết tắt đã tồn tại',
            'code.regex' => 'Mã viết tắt chỉ gồm chữ và số',
            'code.max' => 'Mã viết tắt không được vượt quá 10 ký tự',
            'icon.max' => 'Tên icon không được vượt quá 50 ký tự',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $danhMuc = DanhMuc::create($this->categoryPayload($request));

        return response()->json([
            'success' => true,
            'message' => 'Tạo danh mục thành công',
            'data' => $danhMuc
        ], 201);
    }

    /**
     * Update the specified category in storage.
     */
    public function update(Request $request, DanhMuc $category)
    {
        $validator = Validator::make($request->all(), [
            'name' => [
                'required', 'string', 'max:255',
                'unique:danh_mucs,ten,' . $category->ma_dm . ',ma_dm',
                $this->uniqueFoldedName($category),
            ],
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('danh_mucs', 'ma_danh_muc')->ignore($category->ma_dm, 'ma_dm')],
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ], [
            'name.required' => 'Tên danh mục là bắt buộc',
            'name.unique' => 'Tên danh mục đã tồn tại',
            'name.max' => 'Tên danh mục không được vượt quá 255 ký tự',
            'code.unique' => 'Mã viết tắt đã tồn tại',
            'code.regex' => 'Mã viết tắt chỉ gồm chữ và số',
            'code.max' => 'Mã viết tắt không được vượt quá 10 ký tự',
            'icon.max' => 'Tên icon không được vượt quá 50 ký tự',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $category->update($this->categoryPayload($request, $category));

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật danh mục thành công',
            'data' => $category
        ]);
    }

    /**
     * Remove the specified category from storage.
     */
    public function destroy(DanhMuc $category)
    {
        // Prevent deletion if category has products
        if ($category->hasProducts()) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa danh mục có sản phẩm. Vui lòng xóa hoặc chuyển sản phẩm sang danh mục khác trước.'
            ], 400);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa danh mục thành công'
        ]);
    }

    private function categoryPayload(Request $request, ?DanhMuc $category = null): array
    {
        $code = ProductCodeGenerator::normalizeCategoryCode($request->input('code'));

        return [
            'ten' => $request->input('name'),
            'ma_danh_muc' => $code ?: ProductCodeGenerator::uniqueCategoryCode($request->input('name'), $category?->ma_dm),
            'bieu_tuong' => $request->input('icon'),
            'mo_ta' => $request->input('description'),
        ];
    }

    /**
     * Rejects a name that only differs from an existing one by accents, punctuation
     * or case: "Cơ - xương - khớp" and "Cơ xương khớp" are one category to a customer
     * browsing the storefront, and the crawler now resolves them to one row too.
     *
     * A closure rather than Rule::unique, which would compare the raw input against
     * the folded column. Enforced here as well as by the unique index so the admin
     * gets a field error instead of a 500 from the driver.
     */
    private function uniqueFoldedName(?DanhMuc $category = null): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($category): void {
            $exists = DanhMuc::query()
                ->where('ten_khong_dau', VietnameseText::fold((string) $value))
                ->when($category, fn ($query) => $query->whereKeyNot($category->ma_dm))
                ->exists();

            if ($exists) {
                $fail('Đã có danh mục cùng tên (không phân biệt dấu và dấu gạch).');
            }
        };
    }
}
