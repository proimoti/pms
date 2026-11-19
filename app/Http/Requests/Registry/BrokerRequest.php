<?php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;

/** Проверка на формата за отговорно лице. */
class BrokerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // правата се проверяват от маршрута
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
