<?php

namespace App\Http\Requests;

use App\Models\UserHealthProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HealthProfileRequest extends FormRequest
{
    /**
     * Tag lists must stay item-per-entry: DrugSafetyService matches each item
     * separately, so blank rows and stray whitespace would create dead terms.
     */
    protected const TAG_FIELDS = ['allergies', 'chronic_conditions', 'current_medications'];

    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        foreach (self::TAG_FIELDS as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_array($value)) {
                continue;
            }

            $payload[$field] = collect($value)
                ->filter(fn ($item) => is_scalar($item))
                ->map(fn ($item) => trim((string) $item))
                ->filter(fn (string $item) => $item !== '')
                ->reject(fn (string $item) => UserHealthProfile::isNegationTerm($item))
                ->unique()
                ->values()
                ->all();
        }

        foreach (['weight_kg', 'notes'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $payload[$field] = ($value === '' || $value === null) ? null : $value;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'allergies' => ['nullable', 'array', 'max:30'],
            'allergies.*' => ['string', 'max:120'],
            'chronic_conditions' => ['nullable', 'array', 'max:30'],
            'chronic_conditions.*' => ['string', 'max:120'],
            'current_medications' => ['nullable', 'array', 'max:30'],
            'current_medications.*' => ['string', 'max:120'],
            'is_pregnant' => ['nullable', 'boolean'],
            'is_breastfeeding' => ['nullable', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allergies.array' => 'Danh sách dị ứng không hợp lệ.',
            'allergies.max' => 'Chỉ được khai tối đa 30 mục dị ứng.',
            'allergies.*.string' => 'Mỗi mục dị ứng phải là chữ.',
            'allergies.*.max' => 'Mỗi mục dị ứng không được vượt quá 120 ký tự.',
            'chronic_conditions.array' => 'Danh sách bệnh nền không hợp lệ.',
            'chronic_conditions.max' => 'Chỉ được khai tối đa 30 bệnh nền.',
            'chronic_conditions.*.string' => 'Mỗi bệnh nền phải là chữ.',
            'chronic_conditions.*.max' => 'Mỗi bệnh nền không được vượt quá 120 ký tự.',
            'current_medications.array' => 'Danh sách thuốc đang dùng không hợp lệ.',
            'current_medications.max' => 'Chỉ được khai tối đa 30 thuốc đang dùng.',
            'current_medications.*.string' => 'Mỗi thuốc đang dùng phải là chữ.',
            'current_medications.*.max' => 'Mỗi thuốc đang dùng không được vượt quá 120 ký tự.',
            'is_pregnant.boolean' => 'Giá trị mang thai không hợp lệ.',
            'is_breastfeeding.boolean' => 'Giá trị cho con bú không hợp lệ.',
            'weight_kg.numeric' => 'Cân nặng phải là số.',
            'weight_kg.min' => 'Cân nặng phải từ 1 kg trở lên.',
            'weight_kg.max' => 'Cân nặng không được vượt quá 500 kg.',
            'notes.string' => 'Ghi chú phải là chữ.',
            'notes.max' => 'Ghi chú không được vượt quá 1000 ký tự.',
            'consent.boolean' => 'Giá trị đồng ý không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return [
            'allergies' => $this->validated('allergies') ?? [],
            'chronic_conditions' => $this->validated('chronic_conditions') ?? [],
            'current_medications' => $this->validated('current_medications') ?? [],
            'is_pregnant' => $this->boolean('is_pregnant'),
            'is_breastfeeding' => $this->boolean('is_breastfeeding'),
            'weight_kg' => $this->validated('weight_kg'),
            'notes' => $this->validated('notes'),
        ];
    }
}
