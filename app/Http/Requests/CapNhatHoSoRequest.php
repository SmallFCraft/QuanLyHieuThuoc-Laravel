<?php

namespace App\Http\Requests;

use App\Models\NguoiDung;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CapNhatHoSoRequest extends FormRequest
{
    /**
     * Chuoi rong → null (chi voi field co trong request — khong ghi de khi form chi gui name/email).
     */
    public const COLUMN_MAP = [
        'name' => 'ho_ten',
        'birth_date' => 'ngay_sinh',
        'gender' => 'gioi_tinh',
        'address' => 'dia_chi',
        'phone' => 'so_dien_thoai',
    ];

    protected function prepareForValidation(): void
    {
        $payload = [];
        foreach (['phone', 'address', 'birth_date', 'gender'] as $field) {
            if (! $this->has($field)) {
                continue;
            }
            $v = $this->input($field);
            $payload[$field] = ($v === '' || $v === null) ? null : $v;
        }
        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(NguoiDung::class)->ignore($this->user()->ma_nd, 'ma_nd'),
            ],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'regex:/^0[0-9]{9}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.date' => 'Ngày sinh không hợp lệ.',
            'birth_date.before_or_equal' => 'Ngày sinh không được sau hôm nay.',
            'birth_date.after' => 'Ngày sinh không hợp lệ.',
            'gender.in' => 'Giới tính không hợp lệ.',
            'address.max' => 'Địa chỉ không được vượt quá 500 ký tự.',
            'phone.regex' => 'Số điện thoại phải có 10 chữ số và bắt đầu bằng 0.',
        ];
    }
}
