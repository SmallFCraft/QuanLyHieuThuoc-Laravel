<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Chuỗi rỗng → null (chỉ với field có trong request — không ghi đè khi form chỉ gửi name/email).
     */
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
                Rule::unique(User::class)->ignore($this->user()->id),
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
