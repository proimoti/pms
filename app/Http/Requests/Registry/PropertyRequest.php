<?php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Проверка на формата за имот. Видът и отговорното лице са от същата фирма. */
class PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // правата се проверяват от маршрута (само служител с „четене и запис“)
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $sameCompany = fn (string $table) => Rule::exists($table, 'id')
            ->where('company_id', $companyId)
            ->whereNull('archived_at');

        return [
            // При ново записване празен номер = автоматичен.
            'number' => [$this->isMethod('post') ? 'nullable' : 'required', 'integer', 'min:1', 'max:4294967295'],
            'property_type_id' => ['required', 'integer', $sameCompany('property_types')],
            'broker_id' => ['nullable', 'integer', $sameCompany('brokers')],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:150'],
            'street_number' => ['nullable', 'string', 'max:20'],
            'entrance' => ['nullable', 'string', 'max:20'],
            'floor' => ['nullable', 'string', 'max:20'],
            'apartment' => ['nullable', 'string', 'max:20'],
            'area' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
