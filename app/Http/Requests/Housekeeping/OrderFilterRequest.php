<?php

namespace App\Http\Requests\Housekeeping;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_keys(Order::STATUSES))],
            'payment_status' => ['nullable', Rule::in(array_keys(Order::PAYMENT_STATUSES))],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
        ];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.', 'customer_id.exists' => 'Selecciona un cliente existente.'];
    }
}
