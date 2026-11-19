<?php

namespace App\Http\Requests\Registry;

use App\Enums\PartyKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Проверка на формата за собственик или наемател. ЕИК и МОЛ само при юридическо лице. */
class PartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // правата се проверяват от маршрута
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('country')) {
            $this->merge(['country' => 'BG']);
        } else {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }

    public function rules(): array
    {
        return [
            'number' => [$this->isMethod('post') ? 'nullable' : 'required', 'integer', 'min:1', 'max:4294967295'],
            'kind' => ['required', Rule::enum(PartyKind::class)],
            'name' => ['required', 'string', 'max:255'],
            'eik' => ['nullable', 'prohibited_if:kind,individual', 'regex:/^(\d{9}|\d{13})$/'],
            'manager_name' => ['nullable', 'prohibited_if:kind,individual', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:150'],
            'street_number' => ['nullable', 'string', 'max:20'],
            'entrance' => ['nullable', 'string', 'max:20'],
            'floor' => ['nullable', 'string', 'max:20'],
            'apartment' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'eik.regex' => __('registry.eik_format'),
            'eik.prohibited_if' => __('registry.legal_only'),
            'manager_name.prohibited_if' => __('registry.legal_only'),
        ];
    }
}
