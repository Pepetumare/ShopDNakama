<?php

namespace App\Http\Requests\Housekeeping;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in(array_keys(Order::STATUSES))]];
    }

    public function messages(): array
    {
        return ['status.required' => 'Selecciona un estado del pedido.', 'status.in' => 'Selecciona un estado de pedido válido.'];
    }
}
