<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->role === 'Administrator';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product ? $product->MaSP : null;
        $batchId = $product
            ? $product->batches()->orderBy('expiration_date')->orderBy('MaLo')->value('MaLo')
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
                Rule::unique('products', 'batch_number')
                    ->where(fn ($query) => $query->where('name', $this->input('name')))
                    ->ignore($productId, 'MaSP'),
                Rule::unique('product_batches', 'batch_number')->ignore($batchId, 'MaLo'),
            ],
            'MaDM' => 'required|exists:categories,MaDM',
            'image' => $productId ? 'nullable|image|mimes:jpeg,png|max:5120' : 'nullable|image|mimes:jpeg,png|max:5120',
        ];
    }

    /**
     * Get custom messages for validator errors.
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

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if expiration date is less than 6 months from now
            if ($this->has('expiration_date')) {
                $expirationDate = Carbon::parse($this->input('expiration_date'));
                $sixMonthsFromNow = Carbon::now()->addMonths(6);
                
                if ($expirationDate->lessThan($sixMonthsFromNow)) {
                    $validator->warnings()->add('expiration_date', 
                        'Cảnh báo: Sản phẩm sẽ hết hạn trong vòng 6 tháng tới.');
                }
            }
        });
    }
}
