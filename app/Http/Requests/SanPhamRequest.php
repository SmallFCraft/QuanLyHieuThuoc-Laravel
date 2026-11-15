<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SanPhamRequest extends FormRequest
{
    /**
     * Xác định người dùng có được phép thực hiện request này.
     */
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->vai_tro === 'Administrator';
    }

    /**
     * Các luật kiểm tra áp dụng cho request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product ? $product->ma_sp : null;
        $batchId = $product
            ? $product->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->value('ma_lo')
            : null;
        
        return [
            'name' => 'required|string|max:255',
            'ingredients' => 'nullable|string',
            'usage' => 'nullable|string',
            'price' => 'required|integer|min:0|max:99999999',
            'expiration_date' => 'required|date|after:today',
            'stock_quantity' => 'required|integer|min:0',
            'reorder_point' => 'required|integer|min:0',
            'batch_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('san_phams', 'so_lo')
                    ->where(fn ($query) => $query->where('ten', $this->input('name')))
                    ->ignore($productId, 'ma_sp'),
                Rule::unique('lo_san_phams', 'so_lo')->ignore($batchId, 'ma_lo'),
            ],
            'MaDM' => 'required|exists:danh_mucs,ma_dm',
            'image' => $productId ? 'nullable|image|mimes:jpeg,png|max:5120' : 'nullable|image|mimes:jpeg,png|max:5120',
        ];
    }

    /**
     * Thông báo tùy chỉnh cho các lỗi của validator.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Tên sản phẩm là bắt buộc',
            'name.max' => 'Tên sản phẩm không được vượt quá 255 ký tự',
            'price.required' => 'Giá sản phẩm là bắt buộc',
            'price.integer' => 'Giá sản phẩm phải là số nguyên VNĐ',
            'price.min' => 'Giá sản phẩm phải lớn hơn hoặc bằng 0',
            'price.max' => 'Giá sản phẩm không được vượt quá 99.999.999',
            'expiration_date.required' => 'Ngày hết hạn là bắt buộc',
            'expiration_date.date' => 'Ngày hết hạn không hợp lệ',
            'expiration_date.after' => 'Ngày hết hạn phải là ngày trong tương lai',
            'stock_quantity.required' => 'Số lượng tồn kho là bắt buộc',
            'stock_quantity.integer' => 'Số lượng tồn kho phải là số nguyên',
            'stock_quantity.min' => 'Số lượng tồn kho phải lớn hơn hoặc bằng 0',
            'reorder_point.required' => 'Điểm đặt hàng lại là bắt buộc',
            'reorder_point.integer' => 'Điểm đặt hàng lại phải là số nguyên',
            'reorder_point.min' => 'Điểm đặt hàng lại phải lớn hơn hoặc bằng 0',
            'batch_number.required' => 'Số lô là bắt buộc',
            'batch_number.max' => 'Số lô không được vượt quá 100 ký tự',
            'batch_number.unique' => 'Số lô đã tồn tại cho sản phẩm này',
            'MaDM.required' => 'Danh mục là bắt buộc',
            'MaDM.exists' => 'Danh mục không tồn tại',
            'image.image' => 'File phải là hình ảnh',
            'image.mimes' => 'Hình ảnh phải có định dạng: jpeg, png',
            'image.max' => 'Kích thước hình ảnh không được vượt quá 5MB',
        ];
    }

}
