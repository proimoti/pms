<?php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Проверка на формата за бенефициент. IBAN се приема само от служител с
 * правото за ЕГН и IBAN; контролната сума се проверява в модела.
 */
class BeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // правата се проверяват от маршрута
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:50', Rule::prohibitedIf(! $this->user()->can_view_sensitive)],
        ];
    }

    public function messages(): array
    {
        return ['iban.prohibited' => __('registry.sensitive_only')];
    }
}
